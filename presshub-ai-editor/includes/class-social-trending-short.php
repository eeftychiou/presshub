<?php
/**
 * Trending roundup shorts: the social media posts on screen while the voice talks about them.
 *
 * A trending roundup ("Τι συζητά η Κύπρος στα social") gets its short like any article, from
 * the Article Short prompt. For a roundup, get_plan() also gives the AI the roundup's posts,
 * numbered (prompt_block(), asset social_trending_short_posts.txt), and the AI adds
 * `post_refs` to the short: for each post the script talks about, its number and the first
 * words of the script where that part starts. attach_plan() finds those words in the script
 * (a reference whose words are not found is dropped, with a log line) and keeps, on the
 * plan, the posts' cards (card()) and where each reference starts, in spoken words.
 *
 * At claim time card_spans() turns the word positions into seconds on the voice's turn
 * timings (PressHub_Social_TikTok_Shorts::word_timer()), and the job hands the worker
 * `tiktok_short.cards` (platform, author, @handle, text, numbers and the post's photo) and
 * `tiktok_short.card_spans` ({card, start, end} in seconds). The worker (1.13.0+) draws a
 * post card for each and shows it on the image slots inside its span; the story images fill
 * the rest. An older worker ignores both fields and shows the story images as before.
 *
 * Cards are drawn from what the trending scan collected, never from screenshots: the
 * author's name and @handle are on the card, as the credit for the post.
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Social_Trending_Short {

    /** Material target of the shorts plan call (the posts' text is clipped for it). */
    const MATERIAL_FOR = 'social_tiktok_shorts';

    /** Fewest anchor words that must match the script for a reference to be placed. */
    const MIN_ANCHOR_WORDS = 2;
    /** Anchor words compared (the AI is asked for 3 to 6). */
    const MAX_ANCHOR_WORDS = 6;
    /** Spoken words a lone reference stays on screen for when there is no next one to end it. */
    const LONE_REF_WORDS = 30;

    /**
     * The roundup's posts, in the order the roundup shows them; empty for any other post.
     *
     * @return array<int, array>
     */
    public static function items( int $post_id ): array {
        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) || ! class_exists( 'PressHub_AI_Social_Trending' ) ) {
            return [];
        }
        $raw = (string) get_post_meta( $post_id, PressHub_AI_Social_Trending::META_ITEMS, true );
        if ( '' === $raw ) {
            return [];
        }
        $items = json_decode( $raw, true );
        if ( ! is_array( $items ) ) {
            return [];
        }
        return array_values( array_filter( $items, static function ( $it ) {
            return is_array( $it ) && '' !== (string) ( $it['platform'] ?? '' ) && '' !== (string) ( $it['key'] ?? '' );
        } ) );
    }

    /** What identifies the posts for the plan's cache: a new set of posts makes a new plan. */
    public static function signature( array $items ): string {
        return empty( $items ) ? '' : md5( implode( ',', array_map( static fn( $it ) => (string) $it['key'], $items ) ) );
    }

    private static function author( array $it ): string {
        $label = PressHub_AI_Social_Trending::PLATFORM_LABELS[ $it['platform'] ] ?? (string) $it['platform'];
        if ( '' !== trim( (string) ( $it['author_name'] ?? '' ) ) ) {
            return trim( (string) $it['author_name'] );
        }
        return '' !== (string) ( $it['handle'] ?? '' ) ? '@' . $it['handle'] : $label;
    }

    /**
     * The posts block added to the shorts prompt of a roundup. Each post's text is clipped to
     * PressHub_AI_Social_Trending::SUMMARY_POST_TEXT_CHARS, recorded as material of the call.
     */
    public static function prompt_block( array $items ): string {
        if ( empty( $items ) ) {
            return '';
        }
        $template = PressHub_Social_Prompt_Loader::load( 'social_trending_short_posts.txt' );
        $limit    = PressHub_AI_Social_Trending::SUMMARY_POST_TEXT_CHARS;
        $lines    = [];
        foreach ( $items as $i => $it ) {
            $label  = PressHub_AI_Social_Trending::PLATFORM_LABELS[ $it['platform'] ] ?? (string) $it['platform'];
            $author = self::author( $it );
            $text   = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $it['text'] ?? '' ) ) );
            if ( class_exists( 'PressHub_AI_Knowledge_Cuts' ) ) {
                /* translators: 1: number of the post in the roundup, 2: author */
                $text = PressHub_AI_Knowledge_Cuts::clip_material( self::MATERIAL_FOR, $text, $limit, sprintf( __( 'Trending post %1$d (%2$s)', 'presshub-ai-editor' ), $i + 1, $author ), '…' );
            } elseif ( mb_strlen( $text ) > $limit ) {
                $text = mb_substr( $text, 0, $limit ) . '…';
            }
            $lines[] = sprintf( '%d. [%s] %s: %s', $i + 1, $label, $author, '' !== $text ? $text : '(βίντεο ή εικόνα χωρίς κείμενο)' );
        }
        return strtr( $template, [ '{posts}' => implode( "\n", $lines ) ] );
    }

    /** A word as compared with an anchor: lower case, no accents, letters and digits only. */
    public static function norm_word( string $word ): string {
        $w = mb_strtolower( $word, 'UTF-8' );
        if ( class_exists( 'Normalizer' ) ) {
            $d = Normalizer::normalize( $w, Normalizer::FORM_D );
            if ( is_string( $d ) ) {
                $w = (string) preg_replace( '/\p{Mn}+/u', '', $d );
            }
        }
        $w = strtr( $w, [ 'ς' => 'σ' ] );
        return (string) preg_replace( '/[^\p{L}\p{N}]+/u', '', $w );
    }

    /** The script's spoken words (speaker tags left out), the way the voice's turns count them. */
    public static function spoken_words( string $script ): array {
        $spoken = (string) preg_replace( '/\[\s*SPEAKER_[12]\s*\]\s*:/iu', ' ', $script );
        return preg_split( '/\s+/u', trim( $spoken ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
    }

    /**
     * Where the anchor words start in the script's spoken words, at or after $from, or -1.
     * The first MAX_ANCHOR_WORDS words are tried, then fewer, down to MIN_ANCHOR_WORDS.
     *
     * @param string[] $norm_words The script's words, normalized with norm_word().
     */
    public static function find_anchor( array $norm_words, string $anchor, int $from = 0 ): int {
        $want = array_values( array_filter( array_map( [ __CLASS__, 'norm_word' ], preg_split( '/\s+/u', trim( $anchor ), -1, PREG_SPLIT_NO_EMPTY ) ?: [] ), 'strlen' ) );
        $want = array_slice( $want, 0, self::MAX_ANCHOR_WORDS );
        // The words of the script, without the ones that are only punctuation.
        $idx = [];
        foreach ( $norm_words as $i => $w ) {
            if ( '' !== $w ) {
                $idx[] = $i;
            }
        }
        for ( $n = count( $want ); $n >= self::MIN_ANCHOR_WORDS; $n-- ) {
            $needle = array_slice( $want, 0, $n );
            for ( $k = 0; $k + $n <= count( $idx ); $k++ ) {
                if ( $idx[ $k ] < $from ) {
                    continue;
                }
                $hit = true;
                for ( $j = 0; $j < $n; $j++ ) {
                    if ( $norm_words[ $idx[ $k + $j ] ] !== $needle[ $j ] ) {
                        $hit = false;
                        break;
                    }
                }
                if ( $hit ) {
                    return $idx[ $k ];
                }
            }
        }
        return -1;
    }

    /** The card of one post, as the worker draws it. */
    public static function card( array $it ): array {
        $card = [
            'platform' => (string) $it['platform'],
            'author'   => self::author( $it ),
            'handle'   => (string) ( $it['handle'] ?? '' ),
            'text'     => trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $it['text'] ?? '' ) ) ),
            'likes'    => (int) ( $it['likes'] ?? 0 ),
            'comments' => (int) ( $it['comments'] ?? 0 ),
            'shares'   => (int) ( $it['shares'] ?? 0 ),
            'views'    => (int) ( $it['views'] ?? 0 ),
        ];
        $photo = (string) ( $it['thumbnail'] ?? '' );
        if ( preg_match( '#^https://#', $photo ) ) {
            $card['photo'] = $photo;
        }
        return $card;
    }

    /**
     * Adds the posts the short talks about to a parsed roundup plan: `post_cards` (one per
     * post, in the order first heard), `post_refs` ({card, from} with `from` the spoken word
     * where the part starts) and `script_words`. A plan without usable references is
     * returned as it is (the short shows its story images only).
     *
     * @param array $shorts One-short plan from parse_plan().
     * @param string $raw   The AI response the plan was parsed from.
     */
    public static function attach_plan( array $shorts, string $raw, array $items, int $post_id ): array {
        if ( empty( $shorts ) || empty( $items ) ) {
            return $shorts;
        }
        $decoded = PressHub_Social_AI_Json::decode( $raw );
        $item    = is_array( $decoded['shorts'] ?? null ) ? ( array_values( $decoded['shorts'] )[0] ?? [] ) : [];
        $refs    = is_array( $item['post_refs'] ?? null ) ? array_values( $item['post_refs'] ) : [];
        $words   = self::spoken_words( (string) $shorts[0]['script'] );
        $norm    = array_map( [ __CLASS__, 'norm_word' ], $words );

        $cards   = [];
        $by_post = [];
        $placed  = [];
        $missed  = [];
        $from    = 0;
        foreach ( $refs as $ref ) {
            $n = is_array( $ref ) ? (int) ( $ref['post'] ?? 0 ) : 0;
            if ( $n < 1 || $n > count( $items ) ) {
                $missed[] = sprintf( 'post %s is not in the list', is_array( $ref ) && is_scalar( $ref['post'] ?? null ) ? (string) $ref['post'] : '?' );
                continue;
            }
            $anchor = is_array( $ref ) && is_string( $ref['words'] ?? null ) ? $ref['words'] : '';
            // References come in the order they are heard; one that is not found after the
            // previous one is looked for from the start (the AI may have listed it out of order).
            $at = self::find_anchor( $norm, $anchor, $from );
            if ( $at < 0 ) {
                $at = self::find_anchor( $norm, $anchor, 0 );
            }
            if ( $at < 0 ) {
                $missed[] = sprintf( 'post %d ("%s" is not in the script)', $n, mb_substr( $anchor, 0, 60 ) );
                continue;
            }
            if ( ! isset( $by_post[ $n ] ) ) {
                $by_post[ $n ] = count( $cards );
                $cards[]       = self::card( $items[ $n - 1 ] );
            }
            $placed[] = [ 'card' => $by_post[ $n ], 'from' => $at ];
            $from     = $at + 1;
        }
        usort( $placed, static fn( $a, $b ) => $a['from'] <=> $b['from'] );
        // Two references starting at the same word: the first one listed stays.
        $unique = [];
        foreach ( $placed as $p ) {
            if ( empty( $unique ) || end( $unique )['from'] !== $p['from'] ) {
                $unique[] = $p;
            }
        }

        if ( ! empty( $missed ) ) {
            PressHub_Social_Logger::warning( sprintf( 'Trending short of post #%d: %d post reference(s) left out: %s.', $post_id, count( $missed ), implode( '; ', $missed ) ) );
        }
        if ( empty( $unique ) ) {
            PressHub_Social_Logger::info( sprintf( 'Trending short of post #%d: the script names none of the roundup\'s posts; it shows its story images only.', $post_id ) );
            return $shorts;
        }
        $shorts[0]['post_cards']   = $cards;
        $shorts[0]['post_refs']    = $unique;
        $shorts[0]['script_words'] = count( $words );
        PressHub_Social_Logger::info( sprintf( 'Trending short of post #%d shows %d post card(s) at %d point(s) of the script.', $post_id, count( $cards ), count( $unique ) ) );
        return $shorts;
    }

    /**
     * When each card is on screen, in seconds of the voice: from the word where its part
     * starts to where the next part starts (the last one for about as long as the others,
     * or LONE_REF_WORDS words when it is alone, never past the script). Empty when the short
     * has no references or no turn timings.
     *
     * @return array<int, array{card:int,start:float,end:float}>
     */
    public static function card_spans( array $short ): array {
        $refs  = array_values( array_filter( (array) ( $short['post_refs'] ?? [] ), 'is_array' ) );
        $words = (int) ( $short['script_words'] ?? 0 );
        $cards = (array) ( $short['post_cards'] ?? [] );
        if ( empty( $refs ) || $words <= 0 || empty( $cards ) ) {
            return [];
        }
        $time_of = PressHub_Social_TikTok_Shorts::word_timer( (array) ( $short['audio']['segments'] ?? [] ), $words );
        if ( null === $time_of ) {
            return [];
        }
        $gaps = [];
        for ( $i = 1; $i < count( $refs ); $i++ ) {
            $gaps[] = (int) $refs[ $i ]['from'] - (int) $refs[ $i - 1 ]['from'];
        }
        sort( $gaps );
        $last_len = empty( $gaps ) ? self::LONE_REF_WORDS : $gaps[ intdiv( count( $gaps ), 2 ) ];
        $spans    = [];
        foreach ( $refs as $i => $ref ) {
            $card = (int) ( $ref['card'] ?? -1 );
            if ( $card < 0 || $card >= count( $cards ) ) {
                continue;
            }
            $from = max( 0, min( $words, (int) $ref['from'] ) );
            $to   = isset( $refs[ $i + 1 ] ) ? (int) $refs[ $i + 1 ]['from'] : min( $words, $from + $last_len );
            $start = round( $time_of( $from ), 2 );
            $end   = round( $time_of( max( $from + 1, $to ) ), 2 );
            if ( $end > $start ) {
                $spans[] = [ 'card' => $card, 'start' => $start, 'end' => $end ];
            }
        }
        return $spans;
    }
}

