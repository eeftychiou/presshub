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
        return "Είσαι ένας έμπειρος αρχισυντάκτης και δημοσιογράφος ειδήσεων. "
            . "Αποστολή σου είναι να συνθέσεις μία ολοκληρωμένη, αντικειμενική και ευανάγνωστη Πρωινή Ενημέρωση (Daily News Briefing) "
            . "στα Ελληνικά για την ημερομηνία {date}, αξιοποιώντας {articles_count} άρθρα από τις παρακάτω πηγές: {sources_list}.\n\n"
            . "Οδηγίες Σύνταξης:\n"
            . "1. Ξεκίνα με έναν σαφή και ελκυστικό κύριο τίτλο σε μορφή Markdown (# Τίτλος) που συνοψίζει το κορυφαίο γεγονός της ημέρας.\n"
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
            $date = gmdate( 'Y-m-d' );
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
            $date = gmdate( 'Y-m-d' );
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
            $date = gmdate( 'Y-m-d' );
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
     * Create WordPress post for the briefing with proper title, category, status, and meta.
     *
     * @param string $story_content HTML or Markdown content of the story.
     * @param string $date          Briefing date (YYYY-MM-DD).
     * @param string $headline      Optional headline override.
     * @return int|WP_Error Post ID on success or WP_Error on failure.
     */
    public function create_wordpress_post( string $story_content, string $date, string $headline = '' ) {
        if ( empty( $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        // Extract headline if not provided
        if ( empty( trim( $headline ) ) ) {
            $headline = $this->extract_top_headline( $story_content );
        }
        $headline = trim( $headline );

        // Format date for title: d/m/Y (e.g. 26/08/2026) or fallback to raw date
        $timestamp = strtotime( $date );
        $formatted_date = ( false !== $timestamp )
            ? ( function_exists( 'date_i18n' ) ? date_i18n( 'd/m/Y', $timestamp ) : date( 'd/m/Y', $timestamp ) )
            : $date;

        $post_title = sprintf( 'Πρωινή Ενημέρωση: %s - %s', $headline, $formatted_date );

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

        return $post_id;
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
            $date = gmdate( 'Y-m-d' );
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
