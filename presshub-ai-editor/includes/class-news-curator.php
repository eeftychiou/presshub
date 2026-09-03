<?php
/**
 * PressHub_AI_News_Curator — Greek Daily News Briefing & Text Curation Agent.
 *
 * Coordinates prompt composition with placeholder hydration ({date}, {sources_list},
 * {articles_count}, {articles_context}), author preset resolution, AI generation,
 * Markdown-to-HTML conversion, and WordPress post creation for morning briefings.
 *
 * @package PressHub_AI_Editor
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-markdown.php';
require_once __DIR__ . '/class-preset-store.php';
require_once __DIR__ . '/class-preset-resolver.php';
require_once __DIR__ . '/class-news-harvester.php';
require_once __DIR__ . '/class-api-client.php';
require_once __DIR__ . '/class-settings-storage.php';

class PressHub_AI_News_Curator {

    /** Option key for briefing category ID. */
    const OPTION_CATEGORY = 'presshub_ai_briefing_text_category';

    /** Option key for briefing post status. */
    const OPTION_STATUS = 'presshub_ai_briefing_text_status';

    /**
     * Issue #65 — Settings-First: editorial prefix prepended to the
     * generated Text Story post title (e.g. "Πρωινή Ενημέρωση:"). The
     * value is read through PressHub_AI_Settings_Storage::get_briefing_text_title_prefix()
     * which clamps it to a documented safe length. An empty string disables
     * the prefix so the curator's headline is used verbatim.
     */
    const OPTION_TITLE_PREFIX = 'presshub_ai_briefing_text_title_prefix';

    /**
     * Issue #65 — Settings-First: date() format token appended after the
     * title prefix and headline (default "d/m/Y"). The value is read through
     * PressHub_AI_Settings_Storage::get_briefing_text_title_date_format()
     * which validates it as a documented date() format token. An empty
     * string disables the date suffix.
     */
    const OPTION_TITLE_DATE_FORMAT = 'presshub_ai_briefing_text_title_date_format';

    /**
     * @internal Tracks whether the most recent format_articles_context() call
     * truncated the input set (either by article count or per-article char cap).
     */
    private static $last_context_truncated = false;

    /**
     * @internal Tracks the original number of articles passed to the most recent
     * format_articles_context() call (before max-articles truncation).
     */
    private static $last_original_articles_count = 0;

    /**
     * @internal Issue #61 — tracks how many articles were actually rendered
     * into the context string by the most recent format_articles_context()
     * call (after the max_articles cap slice). Distinct from the original
     * count so token-log metadata can show pool-vs-sent article counts.
     */
    private static $last_kept_articles_count = 0;

    /**
     * @internal Issue #79 — tracks the source-context the curator used to feed
     * the LLM for the most recent generate_briefing() call (curated_briefing
     * vs harvested_articles vs manual_notes), along with article count and
     * resolved author preset slug. Consumed by create_wordpress_post() to
     * persist meta and by PressHub_AI_Briefing_Admin::get_briefing_status()
     * via ::get_source_context() to surface the source in the Hub stage card.
     *
     * @var array{type:string,count:int,preset:string}|null
     */
    private static $last_source_context = null;

    /**
     * @internal Issue #61 — char count of the *rendered* context string produced by
     * the most recent format_articles_context() call (the prompt the LLM actually
     * received, after both the max_articles slice and the per-article char cap).
     */
    private static $last_capped_chars = 0;

    /**
     * @internal Issue #61 — token estimate of the *rendered* context string produced
     * by the most recent format_articles_context() call, derived from the actual
     * rendered context (not the per-article estimate).
     */
    private static $last_capped_tokens_estimate = 0;

    /**
     * Get the base curation prompt with template placeholders.
     *
     * @return string Base system prompt with placeholders.
     */
    public function get_default_curation_prompt(): string {
        // Issue #65 — Bug A (long-term fix): instruct the LLM to emit ONLY
        // the editorial headline in the opening `<h1>`, not the full masthead
        // (e.g. "Πρωινή Ενημέρωση – <date>: …"). The masthead is assembled in
        // create_wordpress_post() from the operator-configurable
        // presshub_ai_briefing_text_title_prefix and date-format Settings,
        // and a duplicate-prefix guard prevents the same prefix from being
        // applied twice. This means the <h1> in the LLM output now matches
        // the curator's extracted headline exactly, and the post body
        // starts cleanly at the first <h2> section.
        return "Είσαι ένας έμπειρος αρχισυντάκτης και δημοσιογράφος ειδήσεων. "
            . "Αποστολή σου είναι να συνθέσεις μία ολοκληρωμένη, αντικειμενική και ευανάγνωστη Πρωινή Ενημέρωση (Daily News Briefing) "
            . "στα Ελληνικά για την ημερομηνία {date}, αξιοποιώντας {articles_count} άρθρα από τις παρακάτω πηγές: {sources_list}.\n\n"
            . "Οδηγίες Σύνταξης:\n"
            . "1. Ξεκίνα με έναν σαφή και ελκυστικό κύριο τίτλο σε μορφή Markdown (# Τίτλος) που συνοψίζει το κορυφαίο γεγονός της ημέρας — ΜΟΝΟ τον τίτλο, χωρίς πρόθεμα «Πρωινή Ενημέρωση» ή ημερομηνία. Το πρόθεμα και η ημερομηνία θα προστεθούν αυτόματα από το σύστημα στον τίτλο του άρθρου.\n"
            . "2. Χώρισε την ενημέρωση σε ευδιάκριτες θεματικές ενότητες με μεσότιτλους (## Πολιτική & Οικονομία, ## Διεθνή, ## Κοινωνία & Επικαιρότητα).\n"
            . "3. Για κάθε είδηση, ανάδειξε τα βασικά γεγονότα με σαφήνεια και ροή, αναφέροντας την πηγή όπου κρίνεται απαραίτητο, διατηρώντας δημοσιογραφική ουδετερότητα.\n"
            . "4. Μην επινοείς γεγονότα ή λεπτομέρειες που δεν αναφέρονται στο παρεχόμενο υλικό.\n"
            . "5. Χρησιμοποίησε καθαρή μορφοποίηση Markdown (επικεφαλίδες, παραγράφους, λίστες με bullets όπου βοηθά στην ανάγνωση).\n\n"
            . "Άρθρα & Πηγές:\n{articles_context}";
    }

    /**
     * Extract a comma-separated list of unique source names from articles.
     *
     * @param array $articles List of article items.
     * @return string Comma-separated sources or fallback text.
     */
    public function get_sources_list( array $articles ): string {
        $sources = [];
        foreach ( $articles as $article ) {
            if ( ! empty( $article['source'] ) ) {
                $sources[] = trim( (string) $article['source'] );
            }
        }
        $unique = array_values( array_unique( array_filter( $sources ) ) );
        return ! empty( $unique ) ? implode( ', ', $unique ) : __( 'Καμία πηγή', 'presshub-ai-editor' );
    }

    /**
     * Format array of articles into a structured textual context.
     *
     * @param array $articles List of article items.
     * @return string Formatted context string.
     */
    public function format_articles_context( array $articles ): string {
        if ( empty( $articles ) ) {
            return __( 'Δεν υπάρχουν διαθέσιμα άρθρα.', 'presshub-ai-editor' );
        }

        /**
         * Issue #61 — Settings-First: the curation context cap is an
         * operator-configurable WordPress option, read through the storage
         * helper (clamped 1–200, default 40). The floor guard that used to
         * live here is removed — bounds enforcement is the responsibility of
         * PressHub_AI_Settings_Storage::sanitize_curation_max_articles().
         */
        $max_articles = PressHub_AI_Settings_Storage::get_curation_max_articles();

        /**
         * Issue #61 — Settings-First: per-article char cap, read through the
         * storage helper (clamped 100–400000, default 3000).
         */
        $max_chars = PressHub_AI_Settings_Storage::get_curation_max_chars_per_article();

        $original_count = count( $articles );
        $truncated      = false;
        $truncation_suffix = __( '…[περικομμένο]', 'presshub-ai-editor' );
        if ( $original_count > $max_articles ) {
            $articles  = array_slice( $articles, 0, $max_articles );
            $truncated = true;
        }

        $blocks = [];
        foreach ( $articles as $index => $article ) {
            $num     = $index + 1;
            $title   = ! empty( $article['title'] ) ? trim( (string) $article['title'] ) : __( 'Χωρίς τίτλο', 'presshub-ai-editor' );
            $source  = ! empty( $article['source'] ) ? trim( (string) $article['source'] ) : __( 'Άγνωστη πηγή', 'presshub-ai-editor' );
            $url     = ! empty( $article['url'] ) ? trim( (string) $article['url'] ) : '';
            $content = ! empty( $article['content'] ) ? trim( (string) $article['content'] ) : '';

            $block = "### {$num}. {$title}\n";
            $block .= "**Πηγή:** {$source}";
            if ( ! empty( $url ) ) {
                $block .= " | **URL:** {$url}";
            }
            if ( ! empty( $content ) ) {
                if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
                    if ( mb_strlen( $content ) > $max_chars ) {
                        $content = mb_substr( $content, 0, $max_chars ) . $truncation_suffix;
                        $truncated = true;
                    }
                } else {
                    if ( strlen( $content ) > $max_chars ) {
                        $content = substr( $content, 0, $max_chars ) . $truncation_suffix;
                        $truncated = true;
                    }
                }
                $block .= "\n\n{$content}";
            }

            $blocks[] = $block;
        }

        $rendered = implode( "\n\n---\n\n", $blocks );

        self::$last_context_truncated       = $truncated;
        self::$last_original_articles_count = $original_count;
        self::$last_kept_articles_count     = count( $articles );

        // Issue #61 — surface pool-vs-LLM observability. The capped metrics are
        // derived from the *rendered* prompt string (not per-article estimates
        // summed) so they reflect exactly what the LLM saw, including block
        // headers and the "---" separators.
        if ( function_exists( 'mb_strlen' ) ) {
            self::$last_capped_chars = (int) mb_strlen( $rendered );
        } else {
            self::$last_capped_chars = (int) strlen( $rendered );
        }
        if ( class_exists( 'PressHub_AI_Context_Estimator' ) ) {
            self::$last_capped_tokens_estimate = (int) PressHub_AI_Context_Estimator::estimate_tokens( $rendered );
        } else {
            // Defensive fallback: replicate the codepoints/3 heuristic so the
            // metric is always present even if the estimator is unavailable.
            $cp = function_exists( 'mb_strlen' )
                ? (int) mb_strlen( strip_tags( $rendered ), 'UTF-8' )
                : (int) strlen( $rendered );
            self::$last_capped_tokens_estimate = $cp > 0 ? max( 1, intdiv( $cp, 3 ) ) : 0;
        }

        return $rendered;
    }

    /**
     * Returns whether the most recent format_articles_context() call truncated the input set.
     * The flag is updated inside the method via a static stash so callers (e.g. generate_briefing)
     * can surface a notice when the LLM received a reduced prompt.
     *
     * @return bool
     */
    public function was_context_truncated(): bool {
        return ! empty( self::$last_context_truncated );
    }

    /**
     * Returns the original number of articles that were considered for the most recent
     * format_articles_context() call (before max-articles truncation).
     *
     * @return int
     */
    public function last_original_articles_count(): int {
        return (int) ( self::$last_original_articles_count ?? 0 );
    }

    /**
     * Issue #61 — returns the number of articles that were actually rendered
     * into the most recent format_articles_context() output (after the
     * max_articles cap slice). When no truncation occurred this equals
     * last_original_articles_count(); when the cap kicked in it is smaller.
     *
     * @return int
     */
    public function last_kept_articles_count(): int {
        return (int) ( self::$last_kept_articles_count ?? 0 );
    }

    /**
     * Issue #61 — returns the character count of the *rendered* context string produced
     * by the most recent format_articles_context() call (the prompt the LLM actually
     * received, after both the max_articles slice and the per-article char cap).
     *
     * @return int
     */
    public function last_capped_chars(): int {
        return (int) ( self::$last_capped_chars ?? 0 );
    }

    /**
     * Issue #61 — returns the token estimate of the *rendered* context string produced
     * by the most recent format_articles_context() call. Computed from the actual
     * rendered prompt (not summed per-article estimates), so it matches what the LLM
     * tokenizer would see.
     *
     * @return int
     */
    public function last_capped_tokens_estimate(): int {
        return (int) ( self::$last_capped_tokens_estimate ?? 0 );
    }

    /**
     * Hydrate placeholders in a template string with actual briefing data.
     *
     * @param string $template Template string containing {date}, {sources_list}, etc.
     * @param string $date     Briefing date.
     * @param array  $articles Harvested articles.
     * @return string Hydrated text.
     */
    public function hydrate_prompt( string $template, string $date, array $articles ): string {
        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        $replacements = [
            '{date}'             => $date,
            '{sources_list}'     => $this->get_sources_list( $articles ),
            '{articles_count}'   => (string) count( $articles ),
            '{articles_context}' => $this->format_articles_context( $articles ),
        ];

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }

    /**
     * Retrieve the generated morning briefing content for a given date.
     *
     * Looks up briefing post by date using meta _presshub_briefing_date / _presshub_briefing_type = text
     * or snapshot text file, and returns post_content/markdown.
     *
     * @param string $date Briefing date (YYYY-MM-DD).
     * @return string|null Briefing content or null if not found.
     */
    public function get_briefing_content( string $date ): ?string {
        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        // 1. Query WordPress briefing posts by meta
        if ( function_exists( 'get_posts' ) ) {
            $posts = get_posts( [
                'post_type'      => 'post',
                'post_status'    => [ 'publish', 'pending', 'draft', 'future', 'private', 'any' ],
                'posts_per_page' => 1,
                'meta_query'     => [
                    [
                        'key'     => '_presshub_briefing_date',
                        'value'   => $date,
                        'compare' => '=',
                    ],
                    [
                        'key'     => '_presshub_briefing_type',
                        'value'   => 'text',
                        'compare' => '=',
                    ],
                ],
            ] );

            if ( ! empty( $posts ) && is_array( $posts ) ) {
                $post = $posts[0];
                $content = is_object( $post ) ? ( $post->post_content ?? '' ) : ( $post['post_content'] ?? '' );
                if ( '' !== trim( (string) $content ) ) {
                    return (string) $content;
                }
            }
        }

        // In test stub environments or fallback, check POST_META_STORE
        if ( ! empty( $GLOBALS['POST_META_STORE'] ) && is_array( $GLOBALS['POST_META_STORE'] ) ) {
            foreach ( $GLOBALS['POST_META_STORE'] as $post_id => $meta ) {
                if ( ( $meta['_presshub_briefing_date'] ?? '' ) === $date && ( $meta['_presshub_briefing_type'] ?? '' ) === 'text' ) {
                    if ( function_exists( 'get_post' ) ) {
                        $p = get_post( $post_id );
                        if ( $p && ! empty( $p->post_content ) ) {
                            return (string) $p->post_content;
                        }
                    }
                }
            }
        }

        // 2. Check snapshot storage directory for saved text briefing files
        $harvester = new PressHub_AI_News_Harvester();
        $dir       = $harvester->get_snapshot_dir( $date );
        $candidates = [ 'briefing-text.md', 'briefing-text.txt', 'briefing.md', 'briefing.txt' ];

        foreach ( $candidates as $file ) {
            $path = trailingslashit( $dir ) . $file;
            if ( file_exists( $path ) ) {
                $content = @file_get_contents( $path );
                if ( false !== $content && '' !== trim( $content ) ) {
                    return $content;
                }
            }
        }

        return null;
    }

    /**
     * Build the system and user prompts for text curation, applying presets and filters.
     *
     * @param array  $articles             Harvested articles array.
     * @param string $preset_id            Optional preset slug override.
     * @param string $date                 Target briefing date (YYYY-MM-DD).
     * @param array  $selected_article_ids Optional list of selected article IDs to filter by.
     * @return array Associative array with 'system_prompt' and 'user_prompt'.
     */
    public function build_prompt( array $articles, string $preset_id = '', string $date = '', array $selected_article_ids = [] ): array {
        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        // Filter articles if selected_article_ids is provided and non-empty
        if ( ! empty( $selected_article_ids ) && is_array( $selected_article_ids ) ) {
            $filtered = [];
            foreach ( $articles as $idx => $article ) {
                $id      = $article['id'] ?? null;
                $url     = $article['url'] ?? null;
                $str_idx = (string) $idx;

                $matched = false;
                foreach ( $selected_article_ids as $target_id ) {
                    $target_str = (string) $target_id;
                    if ( null !== $id && (string) $id === $target_str ) {
                        $matched = true;
                        break;
                    }
                    if ( null !== $url && (string) $url === $target_str ) {
                        $matched = true;
                        break;
                    }
                    if ( $str_idx === $target_str ) {
                        $matched = true;
                        break;
                    }
                }

                if ( $matched ) {
                    $filtered[] = $article;
                }
            }
            $articles = array_values( $filtered );
        }

        // 1. Base curation prompt & filter
        $base_prompt = $this->get_default_curation_prompt();
        $base_prompt = apply_filters( 'presshub_ai_curator_system_prompt', $base_prompt );

        // 2. Hydrate placeholders
        $system_prompt = $this->hydrate_prompt( $base_prompt, $date, $articles );

        // 3. Resolve author/org/plugin preset
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            $user_id = 1;
        }
        $preset = PressHub_AI_Preset_Resolver::resolve_for_user( $user_id, 'curation', $preset_id );
        if ( null !== $preset && '' !== trim( $preset ) ) {
            $system_prompt .= "\n\n" . trim( $preset );
        }

        // 4. Filter composed system prompt
        $system_prompt = apply_filters( 'presshub_ai_composed_curator_system_prompt', $system_prompt );

        // 5. Build user prompt
        $sources = $this->get_sources_list( $articles );
        $count   = count( $articles );
        $context = $this->format_articles_context( $articles );

        $user_prompt = sprintf(
            "Ημερομηνία: %s\nΑριθμός Άρθρων: %d\nΠηγές: %s\n\nΠαρακάτω ακολουθεί το συλλεχθέν υλικό ειδήσεων:\n\n%s\n\nΠαρακαλώ συνέταξε το πλήρες άρθρο της Πρωινής Ενημέρωσης στα Ελληνικά σε μορφή Markdown.",
            $date,
            $count,
            $sources,
            $context
        );

        return [
            'system_prompt' => $system_prompt,
            'user_prompt'   => $user_prompt,
        ];
    }

    /**
     * Extract top headline summary from text (Markdown or HTML).
     *
     * @param string $content Raw generated text.
     * @return string Extracted headline summary.
     */
    public function extract_top_headline( string $content ): string {
        $clean = trim( $content );

        // HTML <h1> or <h2> tag
        if ( preg_match( '/<h[1-2][^>]*>(.*?)<\/h[1-2]>/is', $clean, $m ) ) {
            $hl = trim( wp_strip_all_tags( $m[1] ) );
            if ( ! empty( $hl ) ) {
                return $hl;
            }
        }

        // Markdown # or ## heading
        if ( preg_match( '/^#{1,2}\s+(.+)$/m', $clean, $m ) ) {
            $hl = trim( wp_strip_all_tags( $m[1] ) );
            if ( ! empty( $hl ) ) {
                return $hl;
            }
        }

        // First non-empty line
        $lines = explode( "\n", wp_strip_all_tags( $clean ) );
        foreach ( $lines as $line ) {
            $t = trim( $line );
            if ( ! empty( $t ) ) {
                return wp_html_excerpt( $t, 80, '...' );
            }
        }

        return __( 'Σύνοψη Ειδήσεων', 'presshub-ai-editor' );
    }

    /**
     * Issue #65 — Strip the leading `<h1>` / `<h2>` block from a post body
     * when it duplicates the title. The curator's LLM is instructed to
     * emit a masthead-style `<h1>` like "Πρωινή Ενημέρωση – <date>: <hl>".
     * Since the same text now lives in the post title field, the body should
     * start with the next section heading (typically `<h2>`) or the first
     * paragraph. This helper removes the first `<h[1-2]>...</h[1-2]>` block
     * it finds, including any leading whitespace, leaving the rest of the
     * body untouched.
     *
     * Defensive: only matches the FIRST `<h1>` or `<h2>` block; if no such
     * block exists at the start, the body is returned unchanged.
     *
     * @param string $html_content HTML body produced by PressHub_AI_Markdown::to_html().
     * @return string Body with the leading `<h[1-2]>` block removed.
     */
    private function strip_leading_heading_block( string $html_content ): string {
        // Match a leading h1/h2 block (and any whitespace before/after it).
        // Patterns: optional leading whitespace + <h[1-2]...>...</h[1-2]> + optional trailing whitespace.
        $stripped = preg_replace( '/^\s*<h[1-2][^>]*>.*?<\/h[1-2]>\s*/is', '', $html_content, 1 );
        return ( null !== $stripped ) ? $stripped : $html_content;
    }

    /**
     * Issue #65 — Compose the WordPress post title for a Text Story from
     * the curator's headline, the operator-configurable title prefix, and
     * the operator-configurable date format. The defensive
     * prefix-collision check avoids producing a duplicated "Πρωινή
     * Ενημέρωση: Πρωινή Ενημέρωση: …" title when the LLM's headline already
     * contains the masthead prefix.
     *
     * @param string $headline       Headline extracted from the curator output.
     * @param string $formatted_date Locale-formatted date string (e.g. "26/08/2026").
     * @return string Composed post title (no surrounding whitespace).
     */
    private function compose_text_story_title( string $headline, string $formatted_date ): string {
        $headline = trim( $headline );

        $prefix = PressHub_AI_Settings_Storage::get_briefing_text_title_prefix();
        $prefix = trim( (string) $prefix );

        $date_format = PressHub_AI_Settings_Storage::get_briefing_text_title_date_format();
        $date_format = trim( (string) $date_format );

        // Defensive: if the headline already contains the masthead prefix
        // word at the start (e.g. "Πρωινή Ενημέρωση – 26/08/2026: …",
        // "Πρωινή Ενημέρωση: …", or "Πρωινή Ενημέρωση …"), don't re-prepend
        // it — just append the date suffix. We compare the *headline word*
        // (the prefix trimmed of trailing punctuation) so we catch the
        // collision regardless of which separator the LLM emitted.
        $prefix_word = trim( $prefix, " \t\n\r\0\x0B:-–—" );
        $headline_starts_with_prefix = ( '' !== $prefix_word )
            && ( '' !== $headline )
            && ( 0 === stripos( $headline, $prefix_word ) )
            && ( strlen( $headline ) > strlen( $prefix_word ) )
            && in_array( substr( $headline, strlen( $prefix_word ), 1 ), [ ' ', ':', '-', '–', '—', "\t" ], true );

        // When the prefix already starts with itself (collision detected) we
        // keep the headline as-is. Otherwise, the prefix is a masthead label
        // such as "Πρωινή Ενημέρωση:" and must be separated from the headline
        // by a single space. If the prefix already ends with a separator
        // (space, colon, dash, en-dash, em-dash) we use it verbatim; otherwise
        // we append " " so the resulting title reads
        //     "<prefix> <headline>"
        // and not "<prefix><headline>".
        if ( $headline_starts_with_prefix ) {
            $title = $headline;
        } elseif ( '' !== $prefix ) {
            $sep = in_array( substr( $prefix, -1 ), [ ' ', ':', '-', '–', '—' ], true ) ? ' ' : '';
            $title = $prefix . $sep . $headline;
        } else {
            $title = $headline;
        }

        $title = trim( $title );

        if ( '' !== $date_format && '' !== $formatted_date ) {
            // Append the date suffix. Use " - " as the separator unless the
            // title already ends with one of the conventional separators.
            $sep = in_array( substr( $title, -1 ), [ ' ', '-', '–', '—' ], true ) ? '' : ' - ';
            $title = $title . $sep . $formatted_date;
        }

        return trim( $title );
    }

    /**
     * Create WordPress post for the briefing with proper title, category, status, and meta.
     *
     * Issue #65 — duplicate-title & body-h1 fix:
     *   - Post title is now composed via compose_text_story_title(), which
     *     reads the title prefix and date format from Settings-First options
     *     and skips re-prepending the prefix when the headline already
     *     begins with it.
     *   - The leading `<h1>` or `<h2>` block is stripped from the body via
     *     strip_leading_heading_block() so the headline is not duplicated
     *     between the title field and the post body.
     *
     * @param string $story_content HTML or Markdown content of the story.
     * @param string $date          Briefing date (YYYY-MM-DD).
     * @param string $headline      Optional headline override.
     * @return int|WP_Error Post ID on success or WP_Error on failure.
     */
    public function create_wordpress_post( string $story_content, string $date, string $headline = '' ) {
        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        // Extract headline if not provided
        if ( empty( trim( $headline ) ) ) {
            $headline = $this->extract_top_headline( $story_content );
        }
        $headline = trim( $headline );

        // Format date for title using the operator-configurable date()
        // format token from Settings (default "d/m/Y" e.g. 26/08/2026),
        // or fallback to the raw date when the token is empty/invalid.
        $timestamp = strtotime( $date );
        $date_format_token = PressHub_AI_Settings_Storage::get_briefing_text_title_date_format();
        $formatted_date = ( false !== $timestamp && '' !== $date_format_token )
            ? ( function_exists( 'date_i18n' ) ? date_i18n( $date_format_token, $timestamp ) : date( $date_format_token, $timestamp ) )
            : $date;

        // Issue #65 — compose title via the Settings-First helper, which
        // applies the prefix-collision guard and reads the prefix/date
        // format from operator-configurable Settings (not hard-coded).
        $post_title = $this->compose_text_story_title( $headline, $formatted_date );

        // Read category setting
        $category_id = (int) get_option( self::OPTION_CATEGORY, 0 );
        $post_category = ( $category_id > 0 ) ? [ $category_id ] : [];

        // Read status setting (default 'pending')
        $post_status = (string) get_option( self::OPTION_STATUS, 'pending' );
        if ( empty( trim( $post_status ) ) ) {
            $post_status = 'pending';
        }

        // Ensure content is valid HTML
        $html_content = PressHub_AI_Markdown::to_html( $story_content );

        // Issue #65 — strip the leading <h1>/<h2> block so the body does
        // not duplicate the headline that now lives in the title field.
        $html_content = $this->strip_leading_heading_block( $html_content );

        $postarr = [
            'post_title'   => $post_title,
            'post_content' => wp_kses_post( $html_content ),
            'post_status'  => $post_status,
            'post_type'    => 'post',
            'meta_input'   => [
                '_presshub_briefing_date' => $date,
                '_presshub_briefing_type' => 'text',
            ],
        ];

        if ( ! empty( $post_category ) ) {
            $postarr['post_category'] = $post_category;
        }

        $post_id = wp_insert_post( $postarr, true );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        if ( empty( $post_id ) || ! is_numeric( $post_id ) ) {
            return new WP_Error( 'post_creation_failed', __( 'Failed to create briefing post.', 'presshub-ai-editor' ) );
        }

        $post_id = (int) $post_id;

        // Persist meta explicitly in addition to meta_input
        update_post_meta( $post_id, '_presshub_briefing_date', $date );
        update_post_meta( $post_id, '_presshub_briefing_type', 'text' );

        // Issue #65 — stash the prefix/date-format state used to compose
        // this title so downstream observers (token log, integration
        // tests) can confirm whether the prefix was applied as configured.
        $applied_prefix = PressHub_AI_Settings_Storage::get_briefing_text_title_prefix();
        $applied_date_format = PressHub_AI_Settings_Storage::get_briefing_text_title_date_format();
        update_post_meta( $post_id, '_presshub_text_title_prefix_applied', (string) $applied_prefix );
        update_post_meta( $post_id, '_presshub_text_title_date_format_applied', (string) $applied_date_format );

        // Issue #79 — surface the source-context the LLM was actually fed so
        // the Briefing Hub stage card can show "All Harvested Articles (N)"
        // vs "Selected Articles Filter" vs "Manual Notes / Uploads" plus the
        // author preset that was applied. generate_briefing() populates these
        // stashes via presshub_ai_curator_set_source_context() before
        // calling create_wordpress_post(); call sites that bypass
        // generate_briefing() (e.g. unit tests, manual triggers) fall back
        // to "curated_briefing" so cards are never blank.
        $source_context = self::last_source_context();
        if ( empty( $source_context ) ) {
            $source_context = [
                'type'  => 'curated_briefing',
                'count' => (int) $this->last_kept_articles_count(),
                'preset' => '',
            ];
        }
        if ( empty( $source_context['preset'] ) ) {
            $source_context['preset'] = (string) $applied_prefix;
        }
        update_post_meta( $post_id, '_presshub_source_context_type', (string) $source_context['type'] );
        update_post_meta( $post_id, '_presshub_source_context_count', (int) $source_context['count'] );
        update_post_meta( $post_id, '_presshub_source_context_preset', (string) $source_context['preset'] );

        return $post_id;
    }

    /**
     * Issue #79 — Resolve and persist source-context for the post being created.
     *
     * Called by generate_briefing() immediately before create_wordpress_post()
     * so the post meta reflects *which* input the LLM was actually fed
     * (curated_briefing vs harvested_articles vs manual_notes) along with
     * the article count and author preset slug. create_wordpress_post() then
     * reads the stash via self::last_source_context() and persists it.
     *
     * @param string $type          'curated_briefing' | 'harvested_articles' | 'manual_notes'.
     * @param int    $count         Number of articles fed into the prompt.
     * @param string $preset_slug   Optional resolved author preset slug.
     */
    public static function set_source_context( string $type, int $count, string $preset_slug = '' ): void {
        $allowed = [ 'curated_briefing', 'harvested_articles', 'manual_notes' ];
        if ( ! in_array( $type, $allowed, true ) ) {
            $type = 'curated_briefing';
        }
        self::$last_source_context = [
            'type'   => $type,
            'count'  => max( 0, $count ),
            'preset' => $preset_slug,
        ];
    }

    /**
     * Issue #79 — Return the most recent source-context stash for the curator
     * singleton. Consumed by create_wordpress_post() and the Briefing Hub
     * aggregator.
     *
     * @return array{type:string,count:int,preset:string}|array{} Empty array when no context has been set.
     */
    public static function last_source_context(): array {
        return is_array( self::$last_source_context ) ? self::$last_source_context : [];
    }

    /**
     * Issue #79 — Read source-context for a previously created briefing post
     * (used by the Briefing Hub aggregator to display which input the LLM saw).
     *
     * @param int $post_id WordPress post ID.
     * @return array{type:string,count:int,preset:string}|array{type:string,count:int,preset:string} Structured source context (empty-string slots are replaced with safe fallbacks).
     */
    public static function get_source_context( int $post_id ): array {
        if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
            return [
                'type'   => 'curated_briefing',
                'count'  => 0,
                'preset' => '',
            ];
        }

        $type   = (string) get_post_meta( $post_id, '_presshub_source_context_type', true );
        $count  = (int) get_post_meta( $post_id, '_presshub_source_context_count', true );
        $preset = (string) get_post_meta( $post_id, '_presshub_source_context_preset', true );

        $allowed = [ 'curated_briefing', 'harvested_articles', 'manual_notes' ];
        if ( ! in_array( $type, $allowed, true ) ) {
            $type = 'curated_briefing';
        }

        return [
            'type'   => $type,
            'count'  => max( 0, $count ),
            'preset' => $preset,
        ];
    }

    /**
     * Generate daily briefing story from harvested snapshot, format to HTML, and create post.
     *
     * @param string                       $date                 Target date (YYYY-MM-DD).
     * @param PressHub_AI_API_Client|null $api_client           Optional API client instance.
     * @param string                       $preset_id            Optional preset slug.
     * @param array                        $selected_article_ids Optional list of selected article IDs to filter by.
     * @return array|WP_Error Result payload array or WP_Error on failure.
     */
    public function generate_briefing( string $date = '', ?PressHub_AI_API_Client $api_client = null, string $preset_id = '', array $selected_article_ids = [] ) {
        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        // 1. Load snapshot from harvester
        $harvester = new PressHub_AI_News_Harvester();
        $snapshot  = $harvester->load_snapshot( $date );

        if ( empty( $snapshot ) || empty( $snapshot['articles'] ) || ! is_array( $snapshot['articles'] ) ) {
            return new WP_Error(
                'no_articles',
                sprintf( __( 'No harvested articles found for date: %s', 'presshub-ai-editor' ), $date )
            );
        }

        $articles = $snapshot['articles'];

        // 2. Build prompts (with selected_article_ids filter)
        $prompts = $this->build_prompt( $articles, $preset_id, $date, $selected_article_ids );

        // 3. Call AI provider
        if ( null === $api_client ) {
            $api_client = new PressHub_AI_API_Client( 'briefing_text' );
        }
        if ( method_exists( $api_client, 'set_action' ) ) {
            $api_client->set_action( 'briefing_curation' );
        }

        // Issue #61 — surface pool-vs-LLM observability. Compute the
        // un-capped pool totals over the same article set the curator will
        // use downstream so the token-log metadata can show
        // "Of N pool tokens, M were sent to the LLM (cap: X × Y)".
        // The capped totals were already recorded by build_prompt() →
        // format_articles_context() above (last_capped_chars() /
        // last_capped_tokens_estimate()).
        $pool_chars           = 0;
        $pool_tokens_estimate = 0;
        if ( class_exists( 'PressHub_AI_Context_Estimator' ) ) {
            foreach ( $articles as $_art ) {
                $_content = (string) ( $_art['content'] ?? '' );
                if ( '' === $_content ) {
                    continue;
                }
                if ( function_exists( 'mb_strlen' ) ) {
                    $pool_chars += (int) mb_strlen( $_content );
                } else {
                    $pool_chars += (int) strlen( $_content );
                }
                $pool_tokens_estimate += (int) PressHub_AI_Context_Estimator::estimate_tokens( $_content );
            }
        }

        // Issue #61 — Settings-First: the curation cap values come from the
        // operator-configurable WordPress options (read through the storage
        // helpers), never from hard-coded apply_filters defaults.
        $cap_articles          = PressHub_AI_Settings_Storage::get_curation_max_articles();
        $cap_chars_per_article = PressHub_AI_Settings_Storage::get_curation_max_chars_per_article();

        // Issue #61 — the number of articles actually rendered into the
        // LLM prompt (after the max_articles cap) comes from the curator's
        // own kept-count stash, which format_articles_context() populated
        // during build_prompt(). The optional selection filter and the cap
        // are both applied inside build_prompt(), so the pool count above
        // stays the un-capped reference and this is the sent value.
        $used_articles_count = $this->last_kept_articles_count();

        // Issue #61 — write the pool-vs-LLM comparison into the
        // wp_presshub_ai_token_logs.metadata JSON column of the SAME
        // briefing_curation row that call_provider() logs on success, so
        // the structured token log is the single source of truth.
        $curation_metadata = [
            'pool_chars'             => (int) $pool_chars,
            'pool_tokens_estimate'   => (int) $pool_tokens_estimate,
            'capped_chars'           => (int) $this->last_capped_chars(),
            'capped_tokens_estimate' => (int) $this->last_capped_tokens_estimate(),
            'cap_articles'           => (int) $cap_articles,
            'cap_chars_per_article'  => (int) $cap_chars_per_article,
            'articles_count'         => (int) $used_articles_count,
            'articles_count_original'=> (int) $this->last_original_articles_count(),
            'articles_truncated'     => (bool) $this->was_context_truncated(),
        ];

        $response = $api_client->call_provider( $prompts['system_prompt'], $prompts['user_prompt'], false, [], null, $curation_metadata );

        if ( function_exists( 'presshub_ai_log_prompts' ) ) {
            presshub_ai_log_prompts(
                'curation',
                $prompts['system_prompt'],
                $prompts['user_prompt'],
                is_wp_error( $response ) ? 'ERROR: ' . $response->get_error_message() : $response,
                PressHub_AI_API_Client::current_request_meta()
            );
        }

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error( 'Text curation AI generation error: ' . $response->get_error_message() );
            }
            return $response;
        }

        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( sprintf( 'Text curation AI generated successfully for %s (%d chars)', $date, strlen( $response ) ) );
        }

        // Save raw response to snapshot directory as briefing-text.md
        $dir = $harvester->get_snapshot_dir( $date );
        if ( ! is_dir( $dir ) ) {
            if ( function_exists( 'wp_mkdir_p' ) ) {
                wp_mkdir_p( $dir );
            } else {
                @mkdir( $dir, 0755, true );
            }
        }
        @file_put_contents( trailingslashit( $dir ) . 'briefing-text.md', $response );

        // 4. Convert Markdown to HTML
        $html_content = PressHub_AI_Markdown::to_html( $response );

        // 5. Extract headline and create WordPress post
        $headline = $this->extract_top_headline( $response );

        // Issue #79 — record the source-context the LLM was actually fed so
        // create_wordpress_post() can persist it as post meta and the Briefing
        // Hub stage card can later surface "All Harvested Articles (N)" vs
        // "Selected Articles Filter" vs "Manual Notes / Uploads" along with
        // the resolved author preset. A non-empty selection filter overrides
        // the default harvested_articles type.
        $source_type = 'harvested_articles';
        if ( ! empty( $selected_article_ids ) ) {
            $source_type = 'curated_briefing'; // operator pre-selected subset
        }
        self::set_source_context( $source_type, (int) $used_articles_count, (string) $preset_id );

        $post_id  = $this->create_wordpress_post( $html_content, $date, $headline );

        if ( is_wp_error( $post_id ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error( 'Failed creating post for text curation: ' . $post_id->get_error_message() );
            }
            return $post_id;
        }

        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( sprintf( 'Created daily briefing text post #%d for %s ("%s")', $post_id, $date, $headline ) );
        }

        // Issue #61 — the pool/cap totals were computed before the provider
        // call (see above) so they could ride on the briefing_curation
        // token-log row; the same values are mirrored in the payload below
        // so the AJAX handler / Inspector card can render them too.
        return [
            'post_id'                  => $post_id,
            'date'                     => $date,
            'headline'                 => $headline,
            'html_content'             => $html_content,
            'raw_response'             => $response,
            'articles_count'           => $used_articles_count,
            'articles_count_original'  => $this->last_original_articles_count(),
            'articles_truncated'       => $this->was_context_truncated(),
            // Issue #61 — pool-vs-LLM comparison.
            'pool_chars'               => (int) $pool_chars,
            'pool_tokens_estimate'     => (int) $pool_tokens_estimate,
            'capped_chars'             => (int) $this->last_capped_chars(),
            'capped_tokens_estimate'   => (int) $this->last_capped_tokens_estimate(),
            'cap_articles'             => (int) $cap_articles,
            'cap_chars_per_article'    => (int) $cap_chars_per_article,
        ];
    }

    /**
     * Backward-compatible alias for generate_briefing().
     *
     * @param string                       $date                 Target date (YYYY-MM-DD).
     * @param PressHub_AI_API_Client|null $api_client           Optional API client instance.
     * @param string                       $preset_id            Optional preset slug.
     * @param array                        $selected_article_ids Optional list of selected article IDs to filter by.
     * @return array|WP_Error Result payload array or WP_Error on failure.
     */
    public function generate_story( string $date = '', ?PressHub_AI_API_Client $api_client = null, string $preset_id = '', array $selected_article_ids = [] ) {
        return $this->generate_briefing( $date, $api_client, $preset_id, $selected_article_ids );
    }
}
