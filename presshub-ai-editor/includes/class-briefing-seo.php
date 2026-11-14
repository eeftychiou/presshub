<?php
/**
 * Structural SEO for automated Daily Briefing text posts.
 *
 * The AI SEO review of generated briefings kept flagging the same four
 * problems: a headline too long for search results, no internal links to
 * earlier site coverage, no links to the primary sources, and long
 * unbroken paragraphs (especially the opening section). Instead of fixing
 * each post by hand, the briefing pipeline now:
 *
 *  1. tells the model how many characters the headline may use and asks
 *     for short paragraphs and inline source links (prompt_rules());
 *  2. shortens the composed post title to TITLE_MAX_CHARS when the model
 *     still overshoots (fit_headline());
 *  3. keeps only source links that point at the harvested articles (so a
 *     made-up URL never ships), and adds a "Πηγές" list (sanitize_links(),
 *     sources_section());
 *  4. splits paragraphs that are still too long at sentence boundaries
 *     (split_long_paragraphs());
 *  5. after tags are assigned, links the first mention of each tag to its
 *     tag archive and adds a "Διαβάστε επίσης" list of related earlier
 *     posts (add_internal_links()).
 *
 * Every step is deterministic and degrades to "leave the content as it
 * was". Gated by the presshub_ai_briefing_seo_enhance_enabled setting.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Briefing_SEO {

    /** Upper bound for the whole post title (search results cut at ~60-65). */
    const TITLE_MAX_CHARS = 65;

    /** Never shorten a headline below this, even with a long prefix. */
    const MIN_HEADLINE_CHARS = 25;

    /** Paragraphs whose visible text exceeds this are split. */
    const PARAGRAPH_MAX_CHARS = 420;

    /** Target length of each paragraph produced by a split. */
    const PARAGRAPH_TARGET_CHARS = 300;

    /** Inline links from tag mentions to tag archives. */
    const MAX_TAG_LINKS = 4;

    /** Entries in the "Διαβάστε επίσης" list. */
    const MAX_RELATED_POSTS = 4;

    /** Entries in the "Πηγές" list. */
    const MAX_SOURCE_LINKS = 12;

    /** Only posts newer than this are offered as related coverage. */
    const RELATED_MAX_AGE_DAYS = 120;

    /** CSS classes marking the generated sections (also used to stay idempotent). */
    const SOURCES_CLASS = 'presshub-briefing-sources';
    const RELATED_CLASS = 'presshub-briefing-related';

    /** Post meta: what this class changed on a post (for the log and the Briefing Hub). */
    const META_APPLIED = '_presshub_briefing_seo_applied';

    public static function enabled(): bool {
        if ( class_exists( 'PressHub_AI_Settings_Storage' ) && method_exists( 'PressHub_AI_Settings_Storage', 'get_briefing_seo_enhance_enabled' ) ) {
            return PressHub_AI_Settings_Storage::get_briefing_seo_enhance_enabled();
        }
        return true;
    }

    public static function title_max_chars(): int {
        $max = (int) apply_filters( 'presshub_ai_briefing_title_max_chars', self::TITLE_MAX_CHARS );
        return max( 30, min( 120, $max ) );
    }

    // ------------------------------------------------------------------
    // Prompt
    // ------------------------------------------------------------------

    /**
     * Writing rules appended to the curation user prompt. They live in code
     * (not the editable system prompt) so they also apply when an operator
     * has customised the curation prompt.
     */
    public static function prompt_rules( int $headline_budget ): string {
        $headline_budget = max( self::MIN_HEADLINE_CHARS, $headline_budget );
        $rules = [
            sprintf(
                'Ο κύριος τίτλος (# ...) να έχει το πολύ %d χαρακτήρες μαζί με τα κενά. Να ονομάζει με συγκεκριμένους όρους το κορυφαίο θέμα (ή το πολύ δύο θέματα) και να μην απαριθμεί όλες τις ειδήσεις της ημέρας.',
                $headline_budget
            ),
            'Γράφε σε σύντομες παραγράφους των 2-3 προτάσεων (περίπου έως 60 λέξεις). Η εισαγωγή πριν από την πρώτη ενότητα να είναι 1-2 σύντομες παράγραφοι, ποτέ ένα ενιαίο μακρύ μπλοκ.',
            'Όταν μεταφέρεις πληροφορία από άρθρο του υλικού, βάλε ενσωματωμένο σύνδεσμο Markdown προς το URL του άρθρου πάνω στο όνομα της πηγής ή του θεσμού, π.χ. «σύμφωνα με τον [Φιλελεύθερο](https://...)». Χρησιμοποίησε ΜΟΝΟ τα URL που δίνονται στο υλικό, αυτούσια· μην επινοείς και μην τροποποιείς διευθύνσεις.',
            'Μην προσθέτεις δική σου ενότητα «Πηγές» ή «Σχετικά άρθρα» στο τέλος· προστίθενται αυτόματα από το σύστημα.',
        ];
        return "\n\nΚανόνες SEO και αναγνωσιμότητας (υποχρεωτικοί):\n- " . implode( "\n- ", $rules );
    }

    // ------------------------------------------------------------------
    // Title
    // ------------------------------------------------------------------

    /**
     * Shorten a headline to $budget characters without leaving it cut
     * mid-phrase: prefer the longest leading clause that ends at a natural
     * separator, else cut at a word boundary and drop a dangling
     * conjunction or article. No ellipsis (it wastes title characters).
     */
    public static function shorten_headline( string $headline, int $budget ): string {
        $headline = trim( preg_replace( '/\s+/u', ' ', $headline ) );
        $budget   = max( self::MIN_HEADLINE_CHARS, $budget );
        if ( mb_strlen( $headline ) <= $budget ) {
            return $headline;
        }

        // 1. Longest leading clause ending at a separator that still fits.
        $best = '';
        if ( preg_match_all( '/\s*(?:[:;,|·]|\s[–—-])\s/u', $headline, $m, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $m[0] as $match ) {
                $clause = trim( substr( $headline, 0, (int) $match[1] ) );
                $len    = mb_strlen( $clause );
                if ( $len <= $budget && $len >= min( 20, $budget ) && $len > mb_strlen( $best ) ) {
                    $best = $clause;
                }
            }
        }
        // " και " also separates two topics ("Χ και Υ").
        if ( '' === $best && preg_match_all( '/\sκαι\s/u', $headline, $m, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $m[0] as $match ) {
                $clause = trim( substr( $headline, 0, (int) $match[1] ) );
                $len    = mb_strlen( $clause );
                if ( $len <= $budget && $len >= min( 20, $budget ) && $len > mb_strlen( $best ) ) {
                    $best = $clause;
                }
            }
        }
        if ( '' !== $best ) {
            return self::rtrim_punct( $best );
        }

        // 2. Word boundary.
        $cut  = mb_substr( $headline, 0, $budget + 1 );
        $last = mb_strrpos( $cut, ' ' );
        $out  = ( false !== $last && $last > 0 ) ? mb_substr( $cut, 0, $last ) : mb_substr( $headline, 0, $budget );

        $dangling = [ 'και', 'ή', 'για', 'με', 'σε', 'στο', 'στη', 'στην', 'στον', 'στα', 'στις', 'στους', 'του', 'της', 'των', 'τον', 'την', 'το', 'τα', 'τη', 'η', 'ο', 'οι', 'από', 'κατά', 'προς', 'μετά', 'ενώ', 'που', 'ως', 'and', 'or', 'the', 'of', 'to', 'in', 'for', 'with', 'a', 'an' ];
        $words    = explode( ' ', $out );
        while ( count( $words ) > 1 && in_array( mb_strtolower( self::rtrim_punct( end( $words ) ) ), $dangling, true ) ) {
            array_pop( $words );
        }
        return self::rtrim_punct( implode( ' ', $words ) );
    }

    /** Multibyte-safe rtrim of spaces and separators. */
    private static function rtrim_punct( string $s ): string {
        return (string) preg_replace( '/[\s,;:·|\-–—]+$/u', '', $s );
    }

    // ------------------------------------------------------------------
    // Paragraphs
    // ------------------------------------------------------------------

    /**
     * Split <p> blocks whose visible text is longer than $max characters
     * into several paragraphs at sentence ends. Breaks are only placed
     * outside inline tags (<a>, <strong>, ...) so the HTML stays valid, and
     * never after a one- or two-letter abbreviation such as "κ." or "π.χ.".
     */
    public static function split_long_paragraphs( string $html, int $max = self::PARAGRAPH_MAX_CHARS, int $target = self::PARAGRAPH_TARGET_CHARS ): string {
        $out = preg_replace_callback(
            '#<p(\s[^>]*)?>(.*?)</p>#is',
            function ( $m ) use ( $max, $target ) {
                $attrs = $m[1] ?? '';
                $inner = $m[2];
                if ( mb_strlen( trim( strip_tags( $inner ) ) ) <= $max ) {
                    return $m[0];
                }
                $sentences = self::split_sentences( $inner );
                if ( count( $sentences ) < 2 ) {
                    return $m[0];
                }
                $paras   = [];
                $current = '';
                foreach ( $sentences as $s ) {
                    if ( '' !== $current && mb_strlen( strip_tags( $current . ' ' . $s ) ) > $target ) {
                        $paras[] = $current;
                        $current = $s;
                    } else {
                        $current = '' === $current ? $s : $current . ' ' . $s;
                    }
                }
                if ( '' !== $current ) {
                    $paras[] = $current;
                }
                // Avoid a lonely short tail: merge it back if it would still fit.
                $n = count( $paras );
                if ( $n >= 2 && mb_strlen( strip_tags( $paras[ $n - 1 ] ) ) < 80
                    && mb_strlen( strip_tags( $paras[ $n - 2 ] . ' ' . $paras[ $n - 1 ] ) ) <= $max ) {
                    $paras[ $n - 2 ] .= ' ' . $paras[ $n - 1 ];
                    array_pop( $paras );
                }
                $html = '';
                foreach ( $paras as $p ) {
                    $html .= '<p' . $attrs . '>' . $p . '</p>';
                }
                return $html;
            },
            $html
        );
        return null === $out ? $html : $out;
    }

    /**
     * Split a paragraph's inner HTML into sentences at depth-0 boundaries.
     *
     * @return string[]
     */
    private static function split_sentences( string $inner ): array {
        $tokens = preg_split( '/(<[^>]+>)/', $inner, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
        if ( false === $tokens ) {
            return [ $inner ];
        }
        $void      = [ 'br', 'img', 'wbr', 'hr' ];
        $depth     = 0;
        $sentences = [];
        $current   = '';
        foreach ( $tokens as $tok ) {
            if ( '<' === $tok[0] ) {
                if ( preg_match( '#^</#', $tok ) ) {
                    $depth = max( 0, $depth - 1 );
                } elseif ( preg_match( '#^<([a-z0-9]+)#i', $tok, $tm ) && ! in_array( strtolower( $tm[1] ), $void, true ) && ! preg_match( '#/>$#', $tok ) ) {
                    $depth++;
                }
                $current .= $tok;
                continue;
            }
            if ( $depth > 0 ) {
                $current .= $tok;
                continue;
            }
            // Sentence end: . ! ; (Greek question mark) ; … followed by space
            // and a capital letter, digit or opening quote.
            $offset = 0;
            if ( preg_match_all( '/[.!;;…]+["»”\']?\s+(?=[«"“\'(\p{Lu}\p{N}])/u', $tok, $mm, PREG_OFFSET_CAPTURE ) ) {
                foreach ( $mm[0] as $match ) {
                    $pos   = (int) $match[1];
                    $end   = $pos + strlen( $match[0] );
                    $before = substr( $tok, 0, $pos );
                    if ( preg_match( '/(?:^|[^\p{L}])(\p{L}{1,2})$/u', $before ) ) {
                        continue; // Abbreviation such as "κ." or "π.χ."
                    }
                    $current    .= substr( $tok, $offset, $end - $offset );
                    $sentences[] = trim( $current );
                    $current     = '';
                    $offset      = $end;
                }
            }
            $current .= substr( $tok, $offset );
        }
        if ( '' !== trim( $current ) ) {
            $sentences[] = trim( $current );
        }
        return array_values( array_filter( $sentences, function ( $s ) {
            return '' !== $s;
        } ) );
    }

    // ------------------------------------------------------------------
    // Source links
    // ------------------------------------------------------------------

    public static function normalize_url( string $url ): string {
        $url   = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
        $parts = parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return strtolower( rtrim( $url, '/' ) );
        }
        $host = strtolower( preg_replace( '/^www\./', '', $parts['host'] ) );
        $path = rtrim( $parts['path'] ?? '', '/' );
        $q    = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
        return $host . $path . $q;
    }

    private static function host( string $url ): string {
        $host = parse_url( trim( $url ), PHP_URL_HOST );
        return is_string( $host ) ? strtolower( (string) preg_replace( '/^www\./', '', $host ) ) : '';
    }

    private static function home_host(): string {
        return function_exists( 'home_url' ) ? self::host( (string) home_url() ) : '';
    }

    /**
     * Keep links that point at the harvested articles or at this site and
     * unwrap any other link (a URL the model made up), keeping its text.
     * When $articles is empty there is nothing to check against and the
     * links are left alone.
     *
     * @param array $articles Articles sent to the model (title, source, url).
     * @return array{html:string,cited:string[],removed:int} cited = normalized article URLs linked in the text.
     */
    public static function sanitize_links( string $html, array $articles ): array {
        $allowed = [];
        foreach ( $articles as $a ) {
            $url = trim( (string) ( $a['url'] ?? '' ) );
            if ( '' !== $url ) {
                $allowed[ self::normalize_url( $url ) ] = true;
            }
        }
        $home    = self::home_host();
        $cited   = [];
        $removed = 0;

        $out = preg_replace_callback(
            '#<a\b[^>]*?href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            function ( $m ) use ( $allowed, $home, &$cited, &$removed ) {
                $href = trim( $m[2] );
                $host = self::host( $href );
                if ( '' === $host || ( '' !== $home && $host === $home ) ) {
                    return $m[0];
                }
                $norm = self::normalize_url( $href );
                if ( empty( $allowed ) || isset( $allowed[ $norm ] ) ) {
                    if ( isset( $allowed[ $norm ] ) && ! in_array( $norm, $cited, true ) ) {
                        $cited[] = $norm;
                    }
                    return $m[0];
                }
                $removed++;
                return $m[3];
            },
            $html
        );

        return [
            'html'    => null === $out ? $html : $out,
            'cited'   => $cited,
            'removed' => $removed,
        ];
    }

    /**
     * "Πηγές" list: the articles cited in the text, or, when the model cited
     * none, the articles it was given. Capped at MAX_SOURCE_LINKS.
     */
    public static function sources_section( array $articles, array $cited ): string {
        $by_url = [];
        foreach ( $articles as $a ) {
            $url = trim( (string) ( $a['url'] ?? '' ) );
            if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
                continue;
            }
            $norm = self::normalize_url( $url );
            if ( ! isset( $by_url[ $norm ] ) ) {
                $by_url[ $norm ] = $a;
            }
        }
        if ( empty( $by_url ) ) {
            return '';
        }

        $keys = ! empty( $cited ) ? array_values( array_filter( $cited, function ( $k ) use ( $by_url ) {
            return isset( $by_url[ $k ] );
        } ) ) : array_keys( $by_url );
        $keys = array_slice( $keys, 0, self::MAX_SOURCE_LINKS );
        if ( empty( $keys ) ) {
            return '';
        }

        $items = '';
        foreach ( $keys as $k ) {
            $a      = $by_url[ $k ];
            $title  = trim( (string) ( $a['title'] ?? '' ) );
            $source = trim( (string) ( $a['source'] ?? '' ) );
            $label  = '' !== $title ? $title : ( '' !== $source ? $source : self::host( (string) $a['url'] ) );
            $items .= '<li><a href="' . self::esc_attr( (string) $a['url'] ) . '">' . self::esc_html( $label ) . '</a>';
            if ( '' !== $source && $source !== $label ) {
                $items .= ' (' . self::esc_html( $source ) . ')';
            }
            $items .= '</li>';
        }

        return '<h2 class="' . self::SOURCES_CLASS . '">' . self::esc_html( __( 'Πηγές', 'presshub-ai-editor' ) ) . '</h2><ul class="' . self::SOURCES_CLASS . '">' . $items . '</ul>';
    }

    /**
     * Pre-insert pass over the generated HTML: validate source links,
     * split long paragraphs, add the "Πηγές" list.
     *
     * @return array{html:string,report:array}
     */
    public static function prepare_content( string $html, array $articles ): array {
        $links = self::sanitize_links( $html, $articles );
        $html  = $links['html'];

        $before_p = substr_count( strtolower( $html ), '<p' );
        $html     = self::split_long_paragraphs( $html );
        $after_p  = substr_count( strtolower( $html ), '<p' );

        $sources_added = 0;
        if ( false === strpos( $html, self::SOURCES_CLASS ) ) {
            $section = self::sources_section( $articles, $links['cited'] );
            if ( '' !== $section ) {
                $html         .= "\n" . $section;
                $sources_added = substr_count( $section, '<li>' );
            }
        }

        return [
            'html'   => $html,
            'report' => [
                'source_links_inline'  => count( $links['cited'] ),
                'source_links_removed' => $links['removed'],
                'source_list_items'    => $sources_added,
                'paragraphs_split'     => max( 0, $after_p - $before_p ),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Internal links (after tags are assigned)
    // ------------------------------------------------------------------

    /**
     * Link the first mention of each tag (outside headings and existing
     * links) to the tag's archive page.
     *
     * @param array<string,string> $tag_links Tag name => archive URL, most important first.
     */
    public static function link_tag_mentions( string $html, array $tag_links, int $max = self::MAX_TAG_LINKS ): array {
        $remaining = [];
        foreach ( $tag_links as $name => $url ) {
            $name = trim( (string) $name );
            if ( mb_strlen( $name ) >= 3 && '' !== trim( (string) $url ) ) {
                $remaining[ $name ] = (string) $url;
            }
        }
        if ( empty( $remaining ) || $max <= 0 ) {
            return [ 'html' => $html, 'linked' => [] ];
        }

        $tokens = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
        if ( false === $tokens ) {
            return [ 'html' => $html, 'linked' => [] ];
        }

        $in_a = 0;
        $in_heading = 0;
        $in_block = 0;
        $skip_section = false;
        $linked = [];
        foreach ( $tokens as $i => $tok ) {
            if ( '' === $tok ) {
                continue;
            }
            if ( '<' === $tok[0] ) {
                if ( preg_match( '#^<(/?)([a-z0-9]+)#i', $tok, $tm ) ) {
                    $closing = '/' === $tm[1];
                    $tag     = strtolower( $tm[2] );
                    $delta   = $closing ? -1 : 1;
                    if ( 'a' === $tag ) {
                        $in_a = max( 0, $in_a + $delta );
                    } elseif ( preg_match( '/^h[1-6]$/', $tag ) ) {
                        $in_heading = max( 0, $in_heading + $delta );
                        if ( ! $closing ) {
                            // Do not link inside the generated Πηγές / related lists.
                            $skip_section = ( false !== strpos( $tok, self::SOURCES_CLASS ) || false !== strpos( $tok, self::RELATED_CLASS ) );
                        }
                    } elseif ( in_array( $tag, [ 'p', 'li' ], true ) ) {
                        $in_block = max( 0, $in_block + $delta );
                    }
                }
                continue;
            }
            if ( $in_a || $in_heading || ! $in_block || $skip_section || count( $linked ) >= $max ) {
                continue;
            }

            // Find the first match of every remaining tag in this text run,
            // then apply the non-overlapping ones from left to right.
            $hits = [];
            foreach ( $remaining as $name => $url ) {
                $re = '/(?<![\p{L}\p{N}])' . preg_quote( $name, '/' ) . '(?![\p{L}\p{N}])/iu';
                if ( preg_match( $re, $tok, $mm, PREG_OFFSET_CAPTURE ) ) {
                    $hits[] = [ 'pos' => (int) $mm[0][1], 'len' => strlen( $mm[0][0] ), 'text' => $mm[0][0], 'name' => $name, 'url' => $url ];
                }
            }
            if ( empty( $hits ) ) {
                continue;
            }
            usort( $hits, function ( $a, $b ) {
                return $a['pos'] <=> $b['pos'];
            } );
            $new = '';
            $cursor = 0;
            foreach ( $hits as $h ) {
                if ( $h['pos'] < $cursor || count( $linked ) >= $max ) {
                    continue;
                }
                $new   .= substr( $tok, $cursor, $h['pos'] - $cursor );
                $new   .= '<a href="' . self::esc_attr( $h['url'] ) . '">' . $h['text'] . '</a>';
                $cursor = $h['pos'] + $h['len'];
                $linked[] = $h['name'];
                unset( $remaining[ $h['name'] ] );
            }
            $new .= substr( $tok, $cursor );
            $tokens[ $i ] = $new;
        }

        return [ 'html' => implode( '', $tokens ), 'linked' => $linked ];
    }

    /**
     * Earlier published posts sharing the most tags with $post_id.
     * Non-briefing articles rank above other briefings with the same overlap.
     *
     * @return array<int,array{id:int,title:string,url:string}>
     */
    public static function related_posts( int $post_id, array $tag_ids, int $limit = self::MAX_RELATED_POSTS ): array {
        $tag_ids = array_values( array_filter( array_map( 'intval', $tag_ids ) ) );
        if ( $post_id <= 0 || empty( $tag_ids ) || ! function_exists( 'get_posts' ) ) {
            return [];
        }
        $candidates = get_posts( [
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => 30,
            'tag__in'             => $tag_ids,
            'post__not_in'        => [ $post_id ],
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'date_query'          => [ [ 'after' => self::RELATED_MAX_AGE_DAYS . ' days ago' ] ],
        ] );
        if ( empty( $candidates ) || ! is_array( $candidates ) ) {
            return [];
        }

        $ranked = [];
        foreach ( $candidates as $order => $p ) {
            $id = is_object( $p ) ? (int) ( $p->ID ?? 0 ) : (int) ( $p['ID'] ?? 0 );
            if ( $id <= 0 || $id === $post_id ) {
                continue;
            }
            $title = is_object( $p ) ? (string) ( $p->post_title ?? '' ) : (string) ( $p['post_title'] ?? '' );
            if ( '' === trim( $title ) ) {
                continue;
            }
            $shared = 1;
            if ( function_exists( 'wp_get_post_tags' ) ) {
                $ids = wp_get_post_tags( $id, [ 'fields' => 'ids' ] );
                if ( is_array( $ids ) ) {
                    $shared = count( array_intersect( array_map( 'intval', $ids ), $tag_ids ) );
                }
            }
            $is_briefing = function_exists( 'get_post_meta' ) && '' !== (string) get_post_meta( $id, '_presshub_briefing_type', true );
            $url = function_exists( 'get_permalink' ) ? (string) get_permalink( $id ) : '';
            if ( '' === $url ) {
                continue;
            }
            $ranked[] = [
                'id'     => $id,
                'title'  => $title,
                'url'    => $url,
                'shared' => $shared,
                'brief'  => $is_briefing ? 1 : 0,
                'order'  => (int) $order,
            ];
        }
        usort( $ranked, function ( $a, $b ) {
            return [ $b['shared'], $a['brief'], $a['order'] ] <=> [ $a['shared'], $b['brief'], $b['order'] ];
        } );

        $out = [];
        foreach ( array_slice( $ranked, 0, max( 0, $limit ) ) as $r ) {
            $out[] = [ 'id' => $r['id'], 'title' => $r['title'], 'url' => $r['url'] ];
        }
        return $out;
    }

    public static function related_section( array $related ): string {
        if ( empty( $related ) ) {
            return '';
        }
        $items = '';
        foreach ( $related as $r ) {
            $items .= '<li><a href="' . self::esc_attr( (string) $r['url'] ) . '">' . self::esc_html( (string) $r['title'] ) . '</a></li>';
        }
        return '<h2 class="' . self::RELATED_CLASS . '">' . self::esc_html( __( 'Διαβάστε επίσης', 'presshub-ai-editor' ) ) . '</h2><ul class="' . self::RELATED_CLASS . '">' . $items . '</ul>';
    }

    /**
     * Add internal links to $html for a post whose tags are already set.
     * The related list goes before the "Πηγές" list when there is one.
     *
     * @return array{html:string,report:array}
     */
    public static function add_internal_links( int $post_id, string $html ): array {
        $report = [ 'tag_links' => 0, 'related_posts' => 0 ];
        if ( $post_id <= 0 || ! function_exists( 'wp_get_post_tags' ) ) {
            return [ 'html' => $html, 'report' => $report ];
        }
        $terms = wp_get_post_tags( $post_id );
        if ( empty( $terms ) || ! is_array( $terms ) ) {
            return [ 'html' => $html, 'report' => $report ];
        }

        $tag_links = [];
        $tag_ids   = [];
        foreach ( $terms as $t ) {
            $id   = is_object( $t ) ? (int) ( $t->term_id ?? 0 ) : (int) ( $t['term_id'] ?? 0 );
            $name = is_object( $t ) ? (string) ( $t->name ?? '' ) : (string) ( $t['name'] ?? '' );
            if ( $id <= 0 || '' === $name ) {
                continue;
            }
            $tag_ids[] = $id;
            $link      = function_exists( 'get_tag_link' ) ? get_tag_link( $id ) : '';
            if ( is_string( $link ) && '' !== $link ) {
                $tag_links[ $name ] = $link;
            }
        }

        $res  = self::link_tag_mentions( $html, $tag_links );
        $html = $res['html'];
        $report['tag_links'] = count( $res['linked'] );

        if ( false === strpos( $html, self::RELATED_CLASS ) ) {
            $related = self::related_posts( $post_id, $tag_ids );
            $section = self::related_section( $related );
            if ( '' !== $section ) {
                $pos = strpos( $html, '<h2 class="' . self::SOURCES_CLASS . '"' );
                $html = false !== $pos
                    ? substr( $html, 0, $pos ) . $section . "\n" . substr( $html, $pos )
                    : $html . "\n" . $section;
                $report['related_posts'] = count( $related );
            }
        }

        return [ 'html' => $html, 'report' => $report ];
    }

    // ------------------------------------------------------------------
    // Escaping (works with or without WordPress loaded)
    // ------------------------------------------------------------------

    private static function esc_attr( string $s ): string {
        return function_exists( 'esc_url' ) ? (string) esc_url( $s ) : htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
    }

    private static function esc_html( string $s ): string {
        return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8', false );
    }
}
