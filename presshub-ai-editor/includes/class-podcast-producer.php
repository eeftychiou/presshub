<?php
/**
 * PressHub_AI_Podcast_Producer — Greek Daily News Briefing Podcast Producer Agent.
 *
 * Coordinates prompt composition with placeholder hydration ({date}, {articles_context},
 * {sources_list}, {duration_text}, {word_budget}, {host1_name}, {host2_name}), duration-based
 * word budget tuning, preset resolution, AI generation, strict dual-speaker dialogue parsing
 * ([Μαρία]: / [Νίκος]:), and daily briefing script persistence.
 *
 * @package PressHub_AI_Editor
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-preset-store.php';
require_once __DIR__ . '/class-preset-resolver.php';
require_once __DIR__ . '/class-news-harvester.php';
require_once __DIR__ . '/class-news-curator.php';
require_once __DIR__ . '/class-api-client.php';

class PressHub_AI_Podcast_Producer {

    /** Option key for podcast preset slug. */
    const OPTION_PRESET = 'presshub_ai_briefing_podcast_preset';

    /** Option key for target duration option. */
    const OPTION_DURATION = 'presshub_ai_briefing_target_duration';

    /** Option key for female host name. */
    const OPTION_HOST_FEMALE = 'presshub_ai_briefing_host_female';

    /** Option key for male host name. */
    const OPTION_HOST_MALE = 'presshub_ai_briefing_host_male';

    /**
     * Get duration and pacing specifications based on duration option.
     *
     * @param string $duration_option Duration option (e.g. '3_min', '5_min', '10_min').
     * @return array Duration specification array with minutes, target_words, target_turns, and description.
     */
    public function get_duration_specs( string $duration_option ): array {
        $clean = strtolower( trim( $duration_option ) );

        if ( in_array( $clean, [ '3_min', '3_minutes', '3min', '3', '3 minutes' ], true ) ) {
            return [
                'minutes'      => 3,
                'target_words' => 450,
                'target_turns' => '6-8',
                'description'  => __( '3 λεπτά (~450 λέξεις, 6-8 διάλογοι)', 'presshub-ai-editor' ),
            ];
        }

        if ( in_array( $clean, [ '10_min', '10_minutes', '10min', '10', '10 minutes' ], true ) ) {
            return [
                'minutes'      => 10,
                'target_words' => 1500,
                'target_turns' => '20+',
                'description'  => __( '10 λεπτά (~1500 λέξεις, 20+ διάλογοι)', 'presshub-ai-editor' ),
            ];
        }

        // Default to 5 minutes
        return [
            'minutes'      => 5,
            'target_words' => 750,
            'target_turns' => '12-15',
            'description'  => __( '5 λεπτά (~750 λέξεις, 12-15 διάλογοι)', 'presshub-ai-editor' ),
        ];
    }

    /**
     * Get the base Greek podcast producer system prompt with placeholders.
     *
     * @param int $host_count Number of presenters (1 solo, 2 co-hosts, 3 roundtable).
     * @return string Base system prompt template.
     */
    public function get_default_dialogue_prompt( int $host_count = 2 ): string {
        if ( 1 === $host_count ) {
            $intro = "Είσαι ένας εξειδικευμένος παραγωγός podcast και σεναριογράφος ενημερωτικών εκπομπών. "
                . "Αποστολή σου είναι να δημιουργήσεις ένα ζωντανό, ευχάριστο, άμεσο και απόλυτα ενημερωτικό podcast ενημέρωσης (μονόλογο) στα Ελληνικά "
                . "για την ημερομηνία {date}, με έναν κεντρικό παρουσιαστή/δημοσιογράφο: την {host1_name}.\n\n";
            $speaker_rule = "2. Αυστηρή Μορφή Ομιλητή (Speaker Tags):\n"
                . "   - Κάθε ατάκα/παράγραφος ΠΡΕΠΕΙ να ξεκινάει σε νέα γραμμή με την ακριβή ετικέτα του παρουσιαστή: [{host1_name}]:\n"
                . "   - Μη χρησιμοποιείς άλλες ετικέτες ή αφήγηση εκτός του [{host1_name}]:.\n";
            $flow_rule = "3. Ροή & Ύφος Παρουσίασης:\n"
                . "   - Ο παρουσιαστής μεταδίδει τις ειδήσεις με άμεσο, καθαρό, ζεστό και ραδιοφωνικό τόνο.\n"
                . "   - Ξεκινήστε με ένα θερμό καλωσόρισμα (αναφέροντας την ημερομηνία {date} και το PressHub Briefing) και κλείστε με έναν σύντομο αποχαιρετισμό.\n";
        } elseif ( 3 === $host_count ) {
            $intro = "Είσαι ένας εξειδικευμένος παραγωγός podcast και σεναριογράφος ενημερωτικών εκπομπών. "
                . "Αποστολή σου είναι να δημιουργήσεις ένα ζωντανό, ευχάριστο, άμεσο και απόλυτα ενημερωτικό διάλογο podcast στα Ελληνικά "
                . "για την ημερομηνία {date}, ανάμεσα σε τρεις δημοσιογράφους/παρουσιαστές: την {host1_name} (κεντρική παρουσιάστρια/συντονίστρια), "
                . "τον {host2_name} (σχολιαστής/αναλυτής ειδήσεων) και τον {host3_name} (ειδικός αναλυτής επικαιρότητας).\n\n";
            $speaker_rule = "2. Αυστηρή Μορφή Ομιλητών (Speaker Tags):\n"
                . "   - Κάθε ατάκα ΠΡΕΠΕΙ να ξεκινάει σε νέα γραμμή με την ακριβή ετικέτα του ομιλητή: [{host1_name}]:, [{host2_name}]: ή [{host3_name}]:\n"
                . "   - Μη χρησιμοποιείς άλλες ετικέτες ή αφήγηση εκτός των [{host1_name}]:, [{host2_name}]: και [{host3_name}]:.\n";
            $flow_rule = "3. Ροή & Ύφος Διαλόγου:\n"
                . "   - Οι τρεις παρουσιαστές εναλλάσσονται φυσικά, σχολιάζουν τις ειδήσεις με ερωταποκρίσεις, σύντομες παρεμβάσεις και εύστοχες παρατηρήσεις.\n"
                . "   - Ξεκινήστε με ένα θερμό και άμεσο καλωσόρισμα (αναφέροντας την ημερομηνία {date} και το PressHub Briefing) και κλείστε με έναν σύντομο αποχαιρετισμό.\n";
        } else {
            // Default: 2 hosts
            $intro = "Είσαι ένας εξειδικευμένος παραγωγός podcast και σεναριογράφος ενημερωτικών εκπομπών. "
                . "Αποστολή σου είναι να δημιουργήσεις ένα ζωντανό, ευχάριστο, άμεσο και απόλυτα ενημερωτικό διάλογο podcast στα Ελληνικά "
                . "για την ημερομηνία {date}, ανάμεσα σε δύο δημοσιογράφους/παρουσιαστές: την {host1_name} (κεντρική παρουσιάστρια/δημοσιογράφος) "
                . "και τον {host2_name} (σχολιαστής/αναλυτής ειδήσεων).\n\n";
            $speaker_rule = "2. Αυστηρή Μορφή Ομιλητών (Speaker Tags):\n"
                . "   - Κάθε ατάκα ΠΡΕΠΕΙ να ξεκινάει σε νέα γραμμή με την ακριβή ετικέτα του ομιλητή: [{host1_name}]: ή [{host2_name}]:\n"
                . "   - Μη χρησιμοποιείς άλλες ετικέτες ή αφήγηση εκτός των [{host1_name}]: και [{host2_name}]:.\n";
            $flow_rule = "3. Ροή & Ύφος Διαλόγου:\n"
                . "   - Οι δύο παρουσιαστές εναλλάσσονται φυσικά, σχολιάζουν τις ειδήσεις με ερωταποκρίσεις, σύντομες παρεμβάσεις και εύστοχες παρατηρήσεις.\n"
                . "   - Ξεκινήστε με ένα θερμό και άμεσο καλωσόρισμα (αναφέροντας την ημερομηνία {date} και το PressHub Briefing) και κλείστε με έναν σύντομο αποχαιρετισμό.\n";
        }

        $topic_rule = "\n5. Διαχωρισμός Θεματικών Ενοτήτων (Topic Markers):\n"
            . "   - Χώρισε υποχρεωτικά το σενάριο σε διακριτές θεματικές ενότητες με τα ειδικά tags:\n"
            . "     [TOPIC_START: Τίτλος Ενότητας]\n"
            . "     (ατάκες ομιλητών για τη συγκεκριμένη θεματική ενότητα)\n"
            . "     [TOPIC_END]\n"
            . "   - Παράδειγμα:\n"
            . "     [TOPIC_START: Εισαγωγή & Τίτλοι Ειδήσεων]\n"
            . "     [{host1_name}]: Καλωσήρθατε...\n"
            . "     [TOPIC_END]\n"
            . "     [TOPIC_START: Οικονομία & Αγορές]\n"
            . "     ...\n"
            . "     [TOPIC_END]\n";

        return $intro
            . "Βασικές Οδηγίες & Κανόνες Σεναρίου:\n"
            . "1. Στόχος Διάρκειας & Προϋπολογισμός Λέξεων:\n"
            . "   - Στοχευόμενη διάρκεια: {duration_text}\n"
            . "   - Προϋπολογισμός λέξεων: περίπου {word_budget} λέξεις.\n"
            . $speaker_rule
            . $flow_rule
            . "4. Δημοσιογραφική Ακρίβεια:\n"
            . "   - Βασιστείτε αποκλειστικά στο παρεχόμενο υλικό ειδήσεων. Μην επινοείτε ψευδή γεγονότα.\n"
            . "   - Καλύψτε τα σημαντικότερα θέματα από τις πηγές: {sources_list}."
            . $topic_rule;
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
                $block .= "\n\n{$content}";
            }

            $blocks[] = $block;
        }

        return implode( "\n\n---\n\n", $blocks );
    }

    /**
     * Hydrate placeholders in a template string with actual briefing & podcast specs.
     *
     * @param string $template Template string containing {date}, {articles_context}, etc.
     * @param string $date     Briefing date.
     * @param array  $articles Harvested articles.
     * @param string $duration Duration option.
     * @param string $host1    Lead host name.
     * @param string $host2    Secondary host name.
     * @param string $host3    Tertiary host name.
     * @return string Hydrated text.
     */
    public function hydrate_prompt( string $template, string $date, array $articles = [], string $duration = '', string $host1 = 'Μαρία', string $host2 = 'Νίκος', string $host3 = 'Κώστας' ): string {
        if ( empty( $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        $specs = $this->get_duration_specs( $duration );

        $replacements = [
            '{date}'             => $date,
            '{articles_context}' => $this->format_articles_context( $articles ),
            '{sources_list}'     => $this->get_sources_list( $articles ),
            '{duration_text}'    => $specs['description'],
            '{word_budget}'      => (string) $specs['target_words'],
            '{host1_name}'       => $host1,
            '{host2_name}'       => $host2,
            '{host3_name}'       => $host3,
        ];

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }

    /**
     * Build the system and user prompts for podcast dialogue generation, applying presets, context modes and filters.
     *
     * @param array       $articles             Harvested articles array.
     * @param string      $preset_id            Optional preset slug override.
     * @param string      $duration             Target duration option ('3_min', '5_min', '10_min').
     * @param string      $date                 Target briefing date (YYYY-MM-DD).
     * @param string      $context_mode         Context mode ('curated_briefing' or 'harvested_articles').
     * @param array       $selected_article_ids Optional list of selected article IDs to filter by.
     * @param string|null $briefing_text        Optional explicit briefing text to use in 'curated_briefing' mode.
     * @return array Associative array with 'system_prompt' and 'user_prompt'.
     */
    public function build_dialogue_prompt( array $articles, string $preset_id = '', string $duration = '', string $date = '', string $context_mode = 'curated_briefing', array $selected_article_ids = [], ?string $briefing_text = null ): array {
        if ( empty( $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        if ( empty( $duration ) ) {
            $duration = (string) get_option( self::OPTION_DURATION, '5_min' );
        }

        $specs = $this->get_duration_specs( $duration );

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

        // Host configuration
        $host_count = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_count() : (int) get_option( 'presshub_ai_briefing_host_count', 2 );
        if ( $host_count < 1 || $host_count > 3 ) {
            $host_count = 2;
        }

        $host1 = (string) get_option( self::OPTION_HOST_FEMALE, 'Μαρία' );
        if ( empty( trim( $host1 ) ) ) {
            $host1 = 'Μαρία';
        }
        $host1 = apply_filters( 'presshub_ai_podcast_host1_name', $host1 );

        $host2 = (string) get_option( self::OPTION_HOST_MALE, 'Νίκος' );
        if ( empty( trim( $host2 ) ) ) {
            $host2 = 'Νίκος';
        }
        $host2 = apply_filters( 'presshub_ai_podcast_host2_name', $host2 );

        $host3 = (string) get_option( 'presshub_ai_briefing_host_tertiary', 'Κώστας' );
        if ( empty( trim( $host3 ) ) ) {
            $host3 = 'Κώστας';
        }
        $host3 = apply_filters( 'presshub_ai_podcast_host3_name', $host3 );

        // 1. Base dialogue prompt & filter (system prompt defines persona, rules, speaker tags only)
        $base_prompt = $this->get_default_dialogue_prompt( $host_count );
        $base_prompt = apply_filters( 'presshub_ai_podcast_producer_system_prompt', $base_prompt );

        // 2. Hydrate placeholders
        $system_prompt = $this->hydrate_prompt( $base_prompt, $date, $articles, $duration, $host1, $host2, $host3 );

        // 3. Resolve author/org/plugin preset
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            $user_id = 1;
        }

        if ( empty( $preset_id ) ) {
            $preset_id = (string) get_option( self::OPTION_PRESET, '' );
        }

        $preset = PressHub_AI_Preset_Resolver::resolve_for_user( $user_id, 'podcast', $preset_id );
        if ( null !== $preset && '' !== trim( $preset ) ) {
            $system_prompt .= "\n\n" . trim( $preset );
        }

        // 4. Filter composed system prompt
        $system_prompt = apply_filters( 'presshub_ai_composed_podcast_system_prompt', $system_prompt );

        // 5. Build user prompt based on context mode & host count
        if ( 1 === $host_count ) {
            $presenter_mention = sprintf( __( 'την παρουσιάστρια [%s]', 'presshub-ai-editor' ), $host1 );
        } elseif ( 3 === $host_count ) {
            $presenter_mention = sprintf( __( 'τους παρουσιαστές [%s], [%s] και [%s]', 'presshub-ai-editor' ), $host1, $host2, $host3 );
        } else {
            $presenter_mention = sprintf( __( 'τους παρουσιαστές [%s] και [%s]', 'presshub-ai-editor' ), $host1, $host2 );
        }

        if ( 'curated_briefing' === $context_mode ) {
            if ( null === $briefing_text ) {
                $curator = new PressHub_AI_News_Curator();
                $briefing_text = $curator->get_briefing_content( $date );
            }

            if ( ! empty( $briefing_text ) && '' !== trim( $briefing_text ) ) {
                $user_prompt = sprintf(
                    "Ημερομηνία: %s\nΣτόχος Διάρκειας: %s\nΠροϋπολογισμός Λέξεων: περίπου %d λέξεις\n\nΠαρακάτω ακολουθεί το συνταχθέν κείμενο της Πρωινής Ενημέρωσης (Curated Morning Briefing):\n\n%s\n\nΠαρακαλώ συνέταξε το πλήρες κείμενο podcast στα Ελληνικά με %s βασισμένο στην παραπάνω πρωινή ενημέρωση.",
                    $date,
                    $specs['description'],
                    $specs['target_words'],
                    trim( $briefing_text ),
                    $presenter_mention
                );

                return [
                    'system_prompt' => $system_prompt,
                    'user_prompt'   => $user_prompt,
                ];
            }
        }

        // Default / Fallback: 'harvested_articles' mode
        $sources = $this->get_sources_list( $articles );
        $context = $this->format_articles_context( $articles );

        $user_prompt = sprintf(
            "Ημερομηνία: %s\nΣτόχος Διάρκειας: %s\nΠροϋπολογισμός Λέξεων: περίπου %d λέξεις\nΠηγές: %s\n\nΠαρακάτω ακολουθεί το συλλεχθέν υλικό ειδήσεων:\n\n%s\n\nΠαρακαλώ συνέταξε το πλήρες κείμενο podcast στα Ελληνικά με %s.",
            $date,
            $specs['description'],
            $specs['target_words'],
            $sources,
            $context,
            $presenter_mention
        );

        return [
            'system_prompt' => $system_prompt,
            'user_prompt'   => $user_prompt,
        ];
    }

    /**
     * Parse raw Greek podcast script into structured speaker turns.
     *
     * Handles formats such as:
     *   - [Μαρία]: Καλημέρα...
     *   - **[Μαρία]:** Καλημέρα...
     *   - **[Νίκος]**: Καλημέρα...
     *   - **Μαρία:** Καλημέρα...
     *   - Μαρία: Καλημέρα...
     *   - [Κώστας]: Καλημέρα...
     *
     * @param string $raw_script    Raw dialogue script.
     * @param string $female_host   Lead host name (default 'Μαρία').
     * @param string $male_host     Secondary host name (default 'Νίκος').
     * @param string $tertiary_host Tertiary host name (default 'Κώστας').
     * @return array List of structured turns [['speaker' => 'female'|'male'|'tertiary', 'speaker_name' => '...', 'text' => '...'], ...]
     */
    public function parse_script_turns( string $raw_script, string $female_host = 'Μαρία', string $male_host = 'Νίκος', string $tertiary_host = 'Κώστας' ): array {
        $raw_script = trim( $raw_script );
        if ( empty( $raw_script ) ) {
            return [];
        }

        $lines = explode( "\n", $raw_script );
        $turns = [];
        $current_speaker = null;
        $current_speaker_name = null;
        $current_text_lines = [];

        // Regular expression matching speaker lines like:
        // [Μαρία]: ..., **[Μαρία]:** ..., **[Μαρία]**: ..., **Μαρία:** ..., Μαρία: ...
        $tag_pattern = '/^(?:\s*[*_#\s]*)\s*(?:\[|\()?([^\n:\]\)]+)(?:\]|\))?\s*(?:[*_]*)\s*:\s*(?:[*_]*)\s*(.*)$/u';

        foreach ( $lines as $line ) {
            $trimmed_line = trim( $line );
            if ( '' === $trimmed_line ) {
                continue;
            }

            // Ignore topic boundary markers so they never leak into turn text
            if ( preg_match( '/^(?:\[|\<\!\-\-|\={2,3}|\#{2,3})\s*TOPIC(?:_START|_END)?/iu', $trimmed_line ) ) {
                continue;
            }

            if ( preg_match( $tag_pattern, $trimmed_line, $matches ) ) {
                $raw_speaker_tag = trim( $matches[1] );
                $line_content    = trim( $matches[2] );

                // Clean speaker tag of markdown symbols or brackets
                $clean_speaker = trim( preg_replace( '/[^\p{L}\p{N}\s]+/u', '', $raw_speaker_tag ) );

                // Determine speaker identity
                $matched_type = null;
                $matched_name = null;

                if ( false !== mb_stripos( $clean_speaker, $female_host ) || false !== stripos( $clean_speaker, 'maria' ) || false !== mb_stripos( $clean_speaker, 'μαρία' ) || false !== stripos( $clean_speaker, 'female' ) || false !== stripos( $clean_speaker, 'host1' ) || false !== stripos( $clean_speaker, 'host 1' ) ) {
                    $matched_type = 'female';
                    $matched_name = $female_host;
                } elseif ( ! empty( $tertiary_host ) && ( false !== mb_stripos( $clean_speaker, $tertiary_host ) || false !== stripos( $clean_speaker, 'host3' ) || false !== stripos( $clean_speaker, 'host 3' ) || false !== stripos( $clean_speaker, 'tertiary' ) || false !== mb_stripos( $clean_speaker, 'κώστας' ) ) ) {
                    $matched_type = 'tertiary';
                    $matched_name = $tertiary_host;
                } elseif ( false !== mb_stripos( $clean_speaker, $male_host ) || false !== stripos( $clean_speaker, 'nikos' ) || false !== mb_stripos( $clean_speaker, 'νίκος' ) || false !== stripos( $clean_speaker, 'male' ) || false !== stripos( $clean_speaker, 'host2' ) || false !== stripos( $clean_speaker, 'host 2' ) ) {
                    $matched_type = 'male';
                    $matched_name = $male_host;
                }

                if ( null !== $matched_type ) {
                    // Save previous turn if it exists
                    if ( null !== $current_speaker && ! empty( $current_text_lines ) ) {
                        $full_turn_text = trim( implode( "\n", $current_text_lines ) );
                        // Strip any lingering markdown bold wrappers from the text edges
                        $full_turn_text = trim( preg_replace( '/^\*\*|\*\*$/', '', $full_turn_text ) );
                        if ( '' !== $full_turn_text ) {
                            $turns[] = [
                                'speaker'      => $current_speaker,
                                'speaker_name' => $current_speaker_name,
                                'text'         => $full_turn_text,
                            ];
                        }
                    }

                    $current_speaker      = $matched_type;
                    $current_speaker_name = $matched_name;
                    $current_text_lines   = [];

                    // Strip any leading/trailing markdown asterisks from line content
                    $line_content = trim( preg_replace( '/^\*\*|\*\*$/', '', $line_content ) );
                    if ( '' !== $line_content ) {
                        $current_text_lines[] = $line_content;
                    }
                    continue;
                }
            }

            // If we are currently inside a turn, append this continuation line
            if ( null !== $current_speaker ) {
                // Ignore Markdown title headings like "### Title" if they look like header metadata
                if ( 0 === strpos( $trimmed_line, '#' ) && empty( $current_text_lines ) ) {
                    continue;
                }
                $current_text_lines[] = $trimmed_line;
            }
        }

        // Flush last turn
        if ( null !== $current_speaker && ! empty( $current_text_lines ) ) {
            $full_turn_text = trim( implode( "\n", $current_text_lines ) );
            $full_turn_text = trim( preg_replace( '/^\*\*|\*\*$/', '', $full_turn_text ) );
            if ( '' !== $full_turn_text ) {
                $turns[] = [
                    'speaker'      => $current_speaker,
                    'speaker_name' => $current_speaker_name,
                    'text'         => $full_turn_text,
                ];
            }
        }

        return $turns;
    }

    /**
     * Parse podcast script into structured topic sections.
     *
     * Identifies topic boundaries formatted like:
     *   [TOPIC_START: Title of Topic]
     *   ... dialogue ...
     *   [TOPIC_END]
     *
     * Also supports Markdown/HTML variants:
     *   <!-- TOPIC_START: Title --> ... <!-- TOPIC_END -->
     *   === TOPIC: Title ===
     *   ### TOPIC: Title
     *
     * If no explicit topic markers are found, splits the turns into logical chunks (4-6 turns each)
     * to prevent neural multi-speaker voice drift.
     *
     * @param string $raw_script     Raw script text.
     * @param string $female_host    Lead host name.
     * @param string $male_host      Secondary host name.
     * @param string $tertiary_host  Tertiary host name.
     * @param int    $fallback_chunk Fallback turn chunk size (default 5).
     * @return array List of topic blocks: [['title' => '...', 'script' => '...', 'turns' => [...]], ...]
     */
    public function parse_script_topics( string $raw_script, string $female_host = 'Μαρία', string $male_host = 'Νίκος', string $tertiary_host = 'Κώστας', int $fallback_chunk = 5 ): array {
        $raw_script = trim( $raw_script );
        if ( empty( $raw_script ) ) {
            return [];
        }

        $topics = [];

        // Check for [TOPIC_START: <title>] ... [TOPIC_END] or HTML/Markdown markers
        $has_explicit_topics = (
            false !== stripos( $raw_script, 'TOPIC_START' )
            || false !== stripos( $raw_script, 'TOPIC:' )
        );

        if ( $has_explicit_topics ) {
            $lines = explode( "\n", $raw_script );
            $current_title = null;
            $current_topic_lines = [];

            foreach ( $lines as $line ) {
                $trimmed = trim( $line );

                // Match [TOPIC_START: Title] or <!-- TOPIC_START: Title --> or === TOPIC: Title === or ### TOPIC: Title
                if ( preg_match( '/^(?:\[|\<\!\-\-|\={2,3}|\#{2,3})\s*TOPIC(?:_START)?\s*:\s*([^\]\>\=]+)(?:\]|\-\-\>|\={2,3})?/iu', $trimmed, $m ) ) {
                    // Flush previous topic if any
                    if ( null !== $current_title || ! empty( $current_topic_lines ) ) {
                        $topic_text = trim( implode( "\n", $current_topic_lines ) );
                        if ( '' !== $topic_text ) {
                            $turns = $this->parse_script_turns( $topic_text, $female_host, $male_host, $tertiary_host );
                            if ( ! empty( $turns ) ) {
                                $topics[] = [
                                    'title'  => $current_title ?: __( 'Ενότητα', 'presshub-ai-editor' ),
                                    'script' => $topic_text,
                                    'turns'  => $turns,
                                ];
                            }
                        }
                    }

                    $current_title = trim( $m[1] );
                    $current_topic_lines = [];
                    continue;
                }

                // Match [TOPIC_END] or <!-- TOPIC_END -->
                if ( preg_match( '/^(?:\[|\<\!\-\-)\s*TOPIC_END\s*(?:\]|\-\-\>)?/iu', $trimmed ) ) {
                    if ( ! empty( $current_topic_lines ) ) {
                        $topic_text = trim( implode( "\n", $current_topic_lines ) );
                        if ( '' !== $topic_text ) {
                            $turns = $this->parse_script_turns( $topic_text, $female_host, $male_host, $tertiary_host );
                            if ( ! empty( $turns ) ) {
                                $topics[] = [
                                    'title'  => $current_title ?: __( 'Ενότητα', 'presshub-ai-editor' ),
                                    'script' => $topic_text,
                                    'turns'  => $turns,
                                ];
                            }
                        }
                    }
                    $current_title = null;
                    $current_topic_lines = [];
                    continue;
                }

                if ( null !== $current_title || ! empty( $current_topic_lines ) ) {
                    $current_topic_lines[] = $line;
                }
            }

            // Flush remaining lines
            if ( ! empty( $current_topic_lines ) ) {
                $topic_text = trim( implode( "\n", $current_topic_lines ) );
                if ( '' !== $topic_text ) {
                    $turns = $this->parse_script_turns( $topic_text, $female_host, $male_host, $tertiary_host );
                    if ( ! empty( $turns ) ) {
                        $topics[] = [
                            'title'  => $current_title ?: __( 'Ενότητα', 'presshub-ai-editor' ),
                            'script' => $topic_text,
                            'turns'  => $turns,
                        ];
                    }
                }
            }
        }

        // If explicit parsing yielded topics, return them
        if ( ! empty( $topics ) ) {
            return $topics;
        }

        // Fallback: No explicit topic markers found in script.
        // Chunk all parsed turns into groups of $fallback_chunk turns (e.g. 5 turns)
        $all_turns = $this->parse_script_turns( $raw_script, $female_host, $male_host, $tertiary_host );
        if ( empty( $all_turns ) ) {
            return [];
        }

        $chunks = array_chunk( $all_turns, max( 2, $fallback_chunk ) );
        foreach ( $chunks as $idx => $chunk_turns ) {
            $num = $idx + 1;
            $script_parts = [];
            foreach ( $chunk_turns as $turn ) {
                $name = $turn['speaker_name'] ?? ( 'female' === $turn['speaker'] ? $female_host : $male_host );
                $script_parts[] = "[{$name}]: " . $turn['text'];
            }
            $topics[] = [
                'title'  => sprintf( __( 'Ενότητα %d', 'presshub-ai-editor' ), $num ),
                'script' => implode( "\n\n", $script_parts ),
                'turns'  => $chunk_turns,
            ];
        }

        return $topics;
    }

    /**
     * Save generated podcast dialogue script to briefing storage directory.
     *
     * @param string $date   Briefing date (YYYY-MM-DD).
     * @param string $script Dialogue script text.
     * @return bool True on success, false on failure.
     */
    public function save_script( string $date, string $script ): bool {
        if ( empty( $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        $harvester = new PressHub_AI_News_Harvester();
        $dir       = $harvester->get_snapshot_dir( $date );

        if ( ! is_dir( $dir ) ) {
            if ( function_exists( 'wp_mkdir_p' ) ) {
                wp_mkdir_p( $dir );
            } else {
                @mkdir( $dir, 0755, true );
            }
        }

        $path = trailingslashit( $dir ) . 'podcast-script.txt';
        $saved = ( false !== @file_put_contents( $path, $script ) );

        return $saved;
    }

    /**
     * Load podcast dialogue script from briefing storage directory.
     *
     * @param string $date Briefing date (YYYY-MM-DD).
     * @return string|null Script content or null if not found.
     */
    public function get_script( string $date ): ?string {
        if ( empty( $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        $harvester = new PressHub_AI_News_Harvester();
        $path      = trailingslashit( $harvester->get_snapshot_dir( $date ) ) . 'podcast-script.txt';

        if ( ! file_exists( $path ) ) {
            return null;
        }

        $content = @file_get_contents( $path );
        if ( false === $content || '' === trim( $content ) ) {
            return null;
        }

        return $content;
    }

    /**
     * Generate daily podcast dialogue script from harvested articles snapshot or curated briefing.
     *
     * @param string                       $date                 Target date (YYYY-MM-DD).
     * @param PressHub_AI_API_Client|null $api_client           Optional API client instance.
     * @param string                       $preset_id            Optional preset slug override.
     * @param string                       $duration             Optional duration option override ('3_min', '5_min', '10_min').
     * @param string                       $context_mode         Context mode ('curated_briefing' or 'harvested_articles').
     * @param array                        $selected_article_ids Optional list of selected article IDs to filter by.
     * @return array|WP_Error Result payload array or WP_Error on failure.
     */
    public function generate_dialogue_script( string $date = '', ?PressHub_AI_API_Client $api_client = null, string $preset_id = '', string $duration = '', string $context_mode = 'curated_briefing', array $selected_article_ids = [] ) {
        if ( empty( $date ) ) {
            $date = gmdate( 'Y-m-d' );
        }

        // 1. Load snapshot from harvester
        $harvester = new PressHub_AI_News_Harvester();
        $snapshot  = $harvester->load_snapshot( $date );
        $articles  = ( ! empty( $snapshot ) && ! empty( $snapshot['articles'] ) && is_array( $snapshot['articles'] ) ) ? $snapshot['articles'] : [];

        if ( empty( $articles ) ) {
            $briefing_content = null;
            if ( 'curated_briefing' === $context_mode ) {
                $curator = new PressHub_AI_News_Curator();
                $briefing_content = $curator->get_briefing_content( $date );
            }

            if ( empty( $briefing_content ) ) {
                return new WP_Error(
                    'no_articles',
                    sprintf( __( 'No harvested articles found for date: %s', 'presshub-ai-editor' ), $date )
                );
            }
        }

        // 2. Build prompts (context mode, preset, duration, date, article filtering)
        $prompts = $this->build_dialogue_prompt( $articles, $preset_id, $duration, $date, $context_mode, $selected_article_ids );

        // 3. Call AI provider
        if ( null === $api_client ) {
            $api_client = new PressHub_AI_API_Client( 'briefing_podcast' );
        }
        if ( method_exists( $api_client, 'set_action' ) ) {
            $api_client->set_action( 'podcast_script' );
        }

        $response = $api_client->call_provider( $prompts['system_prompt'], $prompts['user_prompt'], false, [] );

        if ( function_exists( 'presshub_ai_log_prompts' ) ) {
            presshub_ai_log_prompts(
                'podcast',
                $prompts['system_prompt'],
                $prompts['user_prompt'],
                is_wp_error( $response ) ? 'ERROR: ' . $response->get_error_message() : $response,
                PressHub_AI_API_Client::current_request_meta()
            );
        }

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error( 'Podcast script AI generation error: ' . $response->get_error_message() );
            }
            return $response;
        }

        // 4. Parse turns
        $host1 = (string) get_option( self::OPTION_HOST_FEMALE, 'Μαρία' );
        if ( empty( trim( $host1 ) ) ) {
            $host1 = 'Μαρία';
        }
        $host1 = apply_filters( 'presshub_ai_podcast_host1_name', $host1 );

        $host2 = (string) get_option( self::OPTION_HOST_MALE, 'Νίκος' );
        if ( empty( trim( $host2 ) ) ) {
            $host2 = 'Νίκος';
        }
        $host2 = apply_filters( 'presshub_ai_podcast_host2_name', $host2 );

        $turns = $this->parse_script_turns( $response, $host1, $host2 );
        if ( empty( $turns ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::warning( 'Failed parsing dialogue turns from AI script: ' . substr( $response, 0, 300 ) );
            }
            return new WP_Error(
                'invalid_dialogue_format',
                __( 'Failed to parse dialogue turns from generated podcast script.', 'presshub-ai-editor' )
            );
        }

        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( sprintf( 'Generated podcast script for %s: %d turns parsed', $date, count( $turns ) ) );
        }

        // 5. Persist script to daily briefing directory
        $this->save_script( $date, $response );

        // 6. Calculate word count
        $word_count = (int) PressHub_AI_Context_Estimator::utf8_word_count( $response );

        return [
            'date'         => $date,
            'raw_script'   => $response,
            'turns'        => $turns,
            'turns_count'  => count( $turns ),
            'word_count'   => $word_count,
            'duration'     => ! empty( $duration ) ? $duration : '5_min',
            'context_mode' => $context_mode,
            'saved'        => true,
        ];
    }
}
