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
     * Issue #90 — Get the base podcast producer system prompt template for a
     * given (style, host_count) combination. Three styles are shipped:
     * default_greek_chat (current Greek NotebookLM-style chat, kept for
     * back-compat), bbc_broadcasting_standards (formal, neutral,
     * third-person English house style), and conversational_news_reporting
     * (informal, first-person, opinionated co-host conversation). All
     * templates use the generic [SPEAKER_N]: label convention rather than
     * concrete presenter names.
     *
     * @param int    $host_count Number of presenters (1 solo, 2 co-hosts, 3 roundtable).
     * @param string $style      Style key. Unknown values fall back to 'default_greek_chat'.
     * @return string Base system prompt template.
     */
    public static function get_default_dialogue_prompt( int $host_count = 2, string $style = 'default_greek_chat' ): string {
        // Issue #90 — validate the $style argument and dispatch to the
        // appropriate style-specific template helper.
        $style = in_array( $style, [ 'default_greek_chat', 'bbc_broadcasting_standards', 'conversational_news_reporting' ], true )
            ? $style
            : 'default_greek_chat';

        if ( 'bbc_broadcasting_standards' === $style ) {
            return self::get_bbc_broadcasting_standards_prompt( $host_count );
        }
        if ( 'conversational_news_reporting' === $style ) {
            return self::get_conversational_news_reporting_prompt( $host_count );
        }
        return self::get_default_greek_chat_prompt( $host_count );
    }

    /**
     * Issue #90 — Internal helper: get the default Greek NotebookLM-style
     * chat template (rewritten to use [SPEAKER_N]: generic labels).
     */
    private static function get_default_greek_chat_prompt( int $host_count ): string {
        if ( 1 === $host_count ) {
            $intro = "Είσαι ένας εξειδικευμένος παραγωγός podcast και σεναριογράφος ενημερωτικών εκπομπών. "
                . "Αποστολή σου είναι να δημιουργήσεις ένα ζωντανό, ευχάριστο, άμεσο και απόλυτα ενημερωτικό podcast ενημέρωσης (μονόλογο) στα Ελληνικά "
                . "για την ημερομηνία {date}, με έναν κεντρικό παρουσιαστή/δημοσιογράφο που θα αποκαλείται στο σενάριο ως [SPEAKER_1].\n\n";
            $speaker_rule = "2. Αυστηρή Μορφή Ομιλητή (Speaker Tags):\n"
                . "   - Κάθε ατάκα/παράγραφος ΠΡΕΠΕΙ να ξεκινάει σε νέα γραμμή με την ακριβή ετικέτα του παρουσιαστή: [SPEAKER_1]:\n"
                . "   - Μη χρησιμοποιείς άλλες ετικέτες ή αφήγηση εκτός του [SPEAKER_1]:.\n";
            $flow_rule = "3. Ροή & Ύφος Παρουσίασης (Φυσικό & Αφηγηματικό - NotebookLM Style):\n"
                . "   - Ο παρουσιαστής μεταδίδει τις ειδήσεις με έναν εξαιρετικά φυσικό, άμεσο, ζεστό και αφηγηματικό τόνο (storytelling).\n"
                . "   - Χρησιμοποίησε λέξεις 'γεμίσματος' (π.χ. 'Λοιπόν...', 'Σκεφτείτε το...', 'Και εδώ είναι το ενδιαφέρον...') για να σπάσεις τον ξύλινο, στημένο δημοσιογραφικό λόγο.\n"
                . "   - Γράψε το σενάριο ακριβώς όπως μιλάει ένας άνθρωπος που σου διηγείται μια ιστορία, με ρητορικές ερωτήσεις και φυσικές παύσεις.\n"
                . "   - Ξεκινήστε με ένα αυθόρμητο καλωσόρισμα (αναφέροντας την ημερομηνία {date} και το PressHub Briefing) και κλείστε με έναν σύντομο αποχαιρετισμό.\n";
        } elseif ( 3 === $host_count ) {
            $intro = "Είσαι ένας εξειδικευμένος παραγωγός podcast και σεναριογράφος ενημερωτικών εκπομπών. "
                . "Αποστολή σου είναι να δημιουργήσεις ένα ζωντανό, ευχάριστο, άμεσο και απόλυτα ενημερωτικό διάλογο podcast στα Ελληνικά "
                . "για την ημερομηνία {date}, ανάμεσα σε τρεις δημοσιογράφους/παρουσιαστές που θα αποκαλούνται στο σενάριο ως [SPEAKER_1] (κεντρικός/συντονιστής), "
                . "[SPEAKER_2] (σχολιαστής/αναλυτής ειδήσεων) και [SPEAKER_3] (ειδικός αναλυτής επικαιρότητας).\n\n";
            $speaker_rule = "2. Αυστηρή Μορφή Ομιλητών (Speaker Tags):\n"
                . "   - Κάθε ατάκα ΠΡΕΠΕΙ να ξεκινάει σε νέα γραμμή με την ακριβή ετικέτα του ομιλητή: [SPEAKER_1]:, [SPEAKER_2]: ή [SPEAKER_3]:\n"
                . "   - Μη χρησιμοποιείς άλλες ετικέτες ή αφήγηση εκτός των [SPEAKER_1]:, [SPEAKER_2]: και [SPEAKER_3]:.\n";
            $flow_rule = "3. Ροή & Ύφος Διαλόγου (Εξαιρετικά Φυσικό & Διαδραστικό - NotebookLM Audio Overview Style):\n"
                . "   - Οι τρεις παρουσιαστές συζητούν, αναλύουν και διαφωνούν φιλικά σαν μια χαλαρή ραδιοφωνική παρέα.\n"
                . "   - Χρησιμοποίησε εξαιρετικά φυσικό, καθημερινό και σχεδόν αδόμητο προφορικό λόγο.\n"
                . "   - Ενσωμάτωσε λέξεις 'γεμίσματος' (π.χ. 'Λοιπόν...', 'Κοίτα...', 'Ναι, αλλά...'), συχνές μικρές διακοπές, δυναμικές αντιδράσεις ('Σωστό!', 'Ακριβώς.') και αυθόρμητες ερωτήσεις.\n"
                . "   - Απόφυγε τον στημένο ειδησεογραφικό λόγο. Γράψε το σενάριο όπως ακριβώς μιλούν οι παρέες στην πραγματικότητα.\n"
                . "   - Ξεκινήστε με ένα αυθόρμητο καλωσόρισμα (π.χ. 'Καλωσήρθατε στο PressHub Briefing! Σήμερα θα κάνουμε μια βαθιά βουτιά στην επικαιρότητα της {date}...') και κλείστε με έναν σύντομο, ζεστό αποχαιρετισμό.\n";
        } else {
            // Default: 2 hosts
            $intro = "Είσαι ένας εξειδικευμένος παραγωγός podcast και σεναριογράφος ενημερωτικών εκπομπών. "
                . "Αποστολή σου είναι να δημιουργήσεις ένα ζωντανό, ευχάριστο, άμεσο και απόλυτα ενημερωτικό διάλογο podcast στα Ελληνικά "
                . "για την ημερομηνία {date}, ανάμεσα σε δύο δημοσιογράφους/παρουσιαστές που θα αποκαλούνται στο σενάριο ως [SPEAKER_1] (κεντρική παρουσιάστρια/δημοσιογράφος) "
                . "και [SPEAKER_2] (σχολιαστής/αναλυτής ειδήσεων).\n\n";
            $speaker_rule = "2. Αυστηρή Μορφή Ομιλητών (Speaker Tags):\n"
                . "   - Κάθε ατάκα ΠΡΕΠΕΙ να ξεκινάει σε νέα γραμμή με την ακριβή ετικέτα του ομιλητή: [SPEAKER_1]: ή [SPEAKER_2]:\n"
                . "   - Μη χρησιμοποιείς άλλες ετικέτες ή αφήγηση εκτός των [SPEAKER_1]: και [SPEAKER_2]:.\n";
            $flow_rule = "3. Ροή & Ύφος Διαλόγου (Εξαιρετικά Φυσικό & Διαδραστικό - NotebookLM Audio Overview Style):\n"
                . "   - Οι δύο παρουσιαστές δεν διαβάζουν απλώς ειδήσεις. Συζητούν, αναλύουν και ανακαλύπτουν τα θέματα μαζί σαν να κάθονται σε ένα καφέ.\n"
                . "   - Χρησιμοποίησε έναν εξαιρετικά φυσικό, καθημερινό και σχεδόν αδόμητο προφορικό λόγο.\n"
                . "   - Ενσωμάτωσε λέξεις 'γεμίσματος' (π.χ. 'Λοιπόν...', 'Εεε...', 'Κοίτα...', 'Καταλαβαίνεις;'), μικρές διακοπές, συμφωνίες (π.χ. 'Ακριβώς!', 'Ναι, ναι.', 'Ακριβώς αυτό.') και ερωτήσεις.\n"
                . "   - Απόφυγε τον 'ξύλινο', στημένο τηλεοπτικό λόγο. Γράψε το σενάριο όπως ακριβώς μιλούν οι άνθρωποι στην πραγματικότητα, με παύσεις και φυσικό συναίσθημα.\n"
                . "   - Ξεκινήστε με ένα αυθόρμητο καλωσόρισμα (π.χ. 'Καλωσήρθατε στο PressHub Briefing! Σήμερα θα κάνουμε μια βαθιά βουτιά στην επικαιρότητα της {date}...') και κλείστε με έναν σύντομο, ζεστό αποχαιρετισμό.\n";
        }

        $topic_rule = "\n5. Διαχωρισμός Θεματικών Ενοτήτων (Topic Markers):\n"
            . "   - Χώρισε υποχρεωτικά το σενάριο σε διακριτές θεματικές ενότητες με τα ειδικά tags:\n"
            . "     [TOPIC_START: Τίτλος Ενότητας]\n"
            . "     (ατάκες ομιλητών για τη συγκεκριμένη θεματική ενότητα)\n"
            . "     [TOPIC_END]\n"
            . "   - Παράδειγμα:\n"
            . "     [TOPIC_START: Εισαγωγή & Τίτλοι Ειδήσεων]\n"
            . "     [SPEAKER_1]: Καλωσήρθατε...\n"
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
            . "4. Δημοσιογραφική Ακρίβεια & Deep Dive Analysis:\n"
            . "   - Βασιστείτε αποκλειστικά στο παρεχόμενο υλικό ειδήσεων, αλλά ΜΗΝ το διαβάζετε στεγνά. Αναλύστε το σε βάθος (Deep Dive).\n"
            . "   - 'Ενώστε τις τελείες' μεταξύ διαφορετικών ειδήσεων για να βρείτε το ευρύτερο νόημα.\n"
            . "   - Χρησιμοποιήστε απλές, καθημερινές αναλογίες και μεταφορές για να εξηγήσετε πολύπλοκα θέματα (π.χ. οικονομικά ή τεχνολογικά) ώστε να είναι κατανοητά στον μέσο ακροατή.\n"
            . "   - Καλύψτε τα σημαντικότερα θέματα από τις πηγές: {sources_list}."
            . $topic_rule;
    }

    /**
     * Issue #90 - Internal helper: BBC Broadcasting Standards template
     * (formal, neutral, third-person, no opinions, no first-person
     * interjections). Operators can translate the user-facing language
     * to whatever their editorial audience reads. Speaker tags use the
     * generic [SPEAKER_N]: convention; presenter names are deliberately
     * omitted.
     */
    private static function get_bbc_broadcasting_standards_prompt( int $host_count ): string {
        if ( 1 === $host_count ) {
            $intro = "You are the lead anchor of a professional broadcast news podcast for the date {date}. "
                . "You will be referred to throughout the script as [SPEAKER_1]. "
                . "Your delivery follows BBC Broadcasting Standards: formal, neutral, third-person, factual, with no opinions and no first-person interjections. "
                . "You report. You do not editorialize.\n\n";
            $speaker_rule = "2. Strict Speaker Tag Form (Speaker Tags):\n"
                . "   - Every line of dialogue MUST start on a new line with the exact speaker tag: [SPEAKER_1]:\n"
                . "   - No other tags, no narration outside [SPEAKER_1]:.\n";
            $flow_rule = "3. Flow & Tone of Delivery (BBC Broadcasting Standards):\n"
                . "   - Tone: formal, measured, neutral, third-person. Address the audience in the third person or with the impersonal 'we' where the editorial voice allows; never use first-person singular ('I think', 'in my opinion').\n"
                . "   - No filler words ('like', 'you know', 'um', 'er'). Pacing is deliberate and unhurried.\n"
                . "   - No opinions, no speculation, no rhetorical questions. State the facts; cite the source. Attribute claims to their origin where possible.\n"
                . "   - Avoid sensationalist adjectives ('shocking', 'incredible', 'unbelievable'). Prefer restrained, descriptive language.\n"
                . "   - Open with a formal programme greeting (mentioning the date {date} and the PressHub Briefing) and close with a brief, professional sign-off.\n";
        } elseif ( 3 === $host_count ) {
            $intro = "You are coordinating a three-person broadcast news podcast panel for the date {date}. "
                . "The three participants are referred to throughout the script as [SPEAKER_1] (lead anchor / coordinator), "
                . "[SPEAKER_2] (commentator / news analyst) and [SPEAKER_3] (specialist subject-matter analyst). "
                . "Delivery follows BBC Broadcasting Standards: formal, neutral, third-person, factual, with no opinions and no first-person interjections.\n\n";
            $speaker_rule = "2. Strict Speaker Tag Form (Speaker Tags):\n"
                . "   - Every line of dialogue MUST start on a new line with the exact speaker tag: [SPEAKER_1]:, [SPEAKER_2]: or [SPEAKER_3]:\n"
                . "   - No other tags, no narration outside the three speaker tags.\n";
            $flow_rule = "3. Flow & Tone of Delivery (BBC Broadcasting Standards):\n"
                . "   - Tone: formal, measured, neutral, third-person. Address the audience in the third person or with the impersonal 'we' where the editorial voice allows; never use first-person singular.\n"
                . "   - No filler words, no casual asides, no banter. The conversation is structured and disciplined.\n"
                . "   - No opinions, no speculation, no rhetorical questions. State the facts; cite the source; attribute claims to their origin where possible.\n"
                . "   - Avoid sensationalist adjectives. Prefer restrained, descriptive language.\n"
                . "   - [SPEAKER_1] moderates turn-taking. [SPEAKER_2] and [SPEAKER_3] respond to direct questions and add factual context; they do not initiate casual asides.\n"
                . "   - Open with a formal programme greeting (e.g. 'Welcome to the PressHub Briefing for {date}.') and close with a brief, professional sign-off.\n";
        } else {
            $intro = "You are coordinating a two-person broadcast news podcast for the date {date}. "
                . "The two participants are referred to throughout the script as [SPEAKER_1] (lead anchor / coordinator) "
                . "and [SPEAKER_2] (commentator / news analyst). "
                . "Delivery follows BBC Broadcasting Standards: formal, neutral, third-person, factual, with no opinions and no first-person interjections.\n\n";
            $speaker_rule = "2. Strict Speaker Tag Form (Speaker Tags):\n"
                . "   - Every line of dialogue MUST start on a new line with the exact speaker tag: [SPEAKER_1]: or [SPEAKER_2]:\n"
                . "   - No other tags, no narration outside the two speaker tags.\n";
            $flow_rule = "3. Flow & Tone of Delivery (BBC Broadcasting Standards):\n"
                . "   - Tone: formal, measured, neutral, third-person. Address the audience in the third person or with the impersonal 'we' where the editorial voice allows; never use first-person singular.\n"
                . "   - No filler words, no casual banter. The conversation is structured and disciplined.\n"
                . "   - No opinions, no speculation, no rhetorical questions. State the facts; cite the source.\n"
                . "   - Avoid sensationalist adjectives. Prefer restrained, descriptive language.\n"
                . "   - [SPEAKER_1] moderates turn-taking. [SPEAKER_2] responds to direct questions and adds factual context.\n"
                . "   - Open with a formal programme greeting (e.g. 'Welcome to the PressHub Briefing for {date}.') and close with a brief, professional sign-off.\n";
        }

        $topic_rule = "\n5. Topic Segmentation (Topic Markers):\n"
            . "   - Divide the script into discrete topic segments using the special tags:\n"
            . "     [TOPIC_START: Segment Title]\n"
            . "     (dialogue turns for this topic segment)\n"
            . "     [TOPIC_END]\n"
            . "   - Example:\n"
            . "     [TOPIC_START: Opening & Headlines]\n"
            . "     [SPEAKER_1]: Welcome to the PressHub Briefing for {date}...\n"
            . "     [TOPIC_END]\n"
            . "     [TOPIC_START: Economy & Markets]\n"
            . "     ...\n"
            . "     [TOPIC_END]\n";

        return $intro
            . "Core Script Rules:\n"
            . "1. Duration & Word Budget:\n"
            . "   - Target duration: {duration_text}\n"
            . "   - Word budget: approximately {word_budget} words.\n"
            . $speaker_rule
            . $flow_rule
            . "4. Journalistic Accuracy & Source Attribution:\n"
            . "   - Base every claim strictly on the provided source material. Do not invent facts, figures, or attributions.\n"
            . "   - Distinguish clearly between reported fact and reported claim ('according to...', 'officials say...', 'the report states...').\n"
            . "   - Use simple, accurate language. Avoid colloquialisms, slang, or culturally specific idioms that may not translate across audiences.\n"
            . "   - Cover the most significant items from the sources: {sources_list}."
            . $topic_rule;
    }

    /**
     * Issue #90 - Internal helper: Conversational News Reporting template
     * (informal, first-person, opinionated co-host conversation, "improved
     * by the host(s)" flavor). Speaker tags use the generic [SPEAKER_N]:
     * convention.
     */
    private static function get_conversational_news_reporting_prompt( int $host_count ): string {
        if ( 1 === $host_count ) {
            $intro = "You are an experienced radio producer of a news podcast and scriptwriter. "
                . "Your task is to create a lively, spontaneous, everyday, and 'improved by the host' podcast (monologue) in Greek "
                . "for the date {date}, with a single anchor/journalist referred to throughout the script as [SPEAKER_1].\n\n";
            $speaker_rule = "2. Strict Speaker Tag Form (Speaker Tags):\n"
                . "   - Every line of dialogue MUST start on a new line with the exact speaker tag: [SPEAKER_1]:\n"
                . "   - No other tags, no narration outside [SPEAKER_1]:.\n";
            $flow_rule = "3. Flow & Tone of Delivery (Conversational & Opinionated, Improved by the Host):\n"
                . "   - The anchor speaks in the first person ('I believe', 'I think', 'this strikes me'). Express personal opinions, questions, comments.\n"
                . "   - The tone is informal, intimate, almost as if talking to a friend over coffee. Humor, irony, rhetorical questions, enthusiastic reactions are welcome.\n"
                . "   - The anchor 'improves' the news: comments on it, interprets it, enriches it with their own observations, connects items together, makes them more interesting.\n"
                . "   - Open with a spontaneous, friendly greeting (mentioning the date {date} and the PressHub Briefing) and close with a brief, warm sign-off.\n";
        } elseif ( 3 === $host_count ) {
            $intro = "You are an experienced radio producer of a news podcast and scriptwriter. "
                . "Your task is to create a lively, spontaneous, everyday, and 'improved by the hosts' podcast in Greek "
                . "for the date {date}, with three journalists/anchors referred to throughout the script as [SPEAKER_1] (lead anchor/coordinator), "
                . "[SPEAKER_2] (commentator/news analyst) and [SPEAKER_3] (specialist subject-matter analyst).\n\n";
            $speaker_rule = "2. Strict Speaker Tag Form (Speaker Tags):\n"
                . "   - Every line of dialogue MUST start on a new line with the exact speaker tag: [SPEAKER_1]:, [SPEAKER_2]: or [SPEAKER_3]:\n"
                . "   - No other tags, no narration outside the three speaker tags.\n";
            $flow_rule = "3. Flow & Tone of Delivery (Conversational & Opinionated, Improved by the Hosts):\n"
                . "   - The three anchors speak in the first person, express personal opinions, react, politely disagree, comment.\n"
                . "   - The tone is informal, intimate, almost like sitting in a cafe and chatting. Humor, irony, small interruptions, dynamic reactions ('Right!', 'Exactly!', 'Well done!') and spontaneous questions are welcome.\n"
                . "   - The anchors 'improve' the news: comment on it, interpret it, enrich it with their own observations, connect items together, make them more interesting.\n"
                . "   - Open with a spontaneous, friendly greeting (e.g. 'Welcome to the PressHub Briefing! Today we will take a deep dive...') and close with a brief, warm sign-off.\n";
        } else {
            $intro = "You are an experienced radio producer of a news podcast and scriptwriter. "
                . "Your task is to create a lively, spontaneous, everyday, and 'improved by the hosts' podcast in Greek "
                . "for the date {date}, with two journalists/anchors referred to throughout the script as [SPEAKER_1] (lead anchor) "
                . "and [SPEAKER_2] (commentator/news analyst).\n\n";
            $speaker_rule = "2. Strict Speaker Tag Form (Speaker Tags):\n"
                . "   - Every line of dialogue MUST start on a new line with the exact speaker tag: [SPEAKER_1]: or [SPEAKER_2]:\n"
                . "   - No other tags, no narration outside the two speaker tags.\n";
            $flow_rule = "3. Flow & Tone of Delivery (Conversational & Opinionated, Improved by the Hosts):\n"
                . "   - The two anchors speak in the first person, express personal opinions, react, politely disagree, comment.\n"
                . "   - The tone is informal, intimate, almost like sitting in a cafe. Humor, irony, small interruptions, agreements ('Exactly!', 'Yes, yes!', 'Well done!') and spontaneous questions are welcome.\n"
                . "   - The anchors 'improve' the news: comment on it, interpret it, enrich it with their own observations, connect items together, make them more interesting.\n"
                . "   - Open with a spontaneous, friendly greeting (e.g. 'Welcome to the PressHub Briefing! Today we will take a deep dive...') and close with a brief, warm sign-off.\n";
        }

        $topic_rule = "\n5. Topic Segmentation (Topic Markers):\n"
            . "   - Divide the script into discrete topic segments using the special tags:\n"
            . "     [TOPIC_START: Segment Title]\n"
            . "     (dialogue turns for this topic segment)\n"
            . "     [TOPIC_END]\n"
            . "   - Example:\n"
            . "     [TOPIC_START: Opening & Headlines]\n"
            . "     [SPEAKER_1]: Welcome to the PressHub Briefing...\n"
            . "     [TOPIC_END]\n"
            . "     [TOPIC_START: Economy & Markets]\n"
            . "     ...\n"
            . "     [TOPIC_END]\n";

        return $intro
            . "Core Script Rules:\n"
            . "1. Duration & Word Budget:\n"
            . "   - Target duration: {duration_text}\n"
            . "   - Word budget: approximately {word_budget} words.\n"
            . $speaker_rule
            . $flow_rule
            . "4. Journalistic Accuracy & Deep Dive Analysis:\n"
            . "   - Base the script strictly on the provided source material, but do not just read it dry. Analyze in depth (Deep Dive), interpret, comment, and connect items.\n"
            . "   - Use simple, everyday analogies and metaphors to explain complex topics so the average listener can understand them.\n"
            . "   - Cover the most significant items from the sources: {sources_list}."
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
     * Issue #90 — Breaking change: the `$host1` / `$host2` / `$host3` parameters
     * and the `{hostN_name}` placeholders have been removed. All built-in prompt
     * templates now use the generic `[SPEAKER_N]:` label convention. Operator
     * custom prompts that still reference `{host1_name}` etc. will retain those
     * literal tokens in the hydrated output; operators should re-save their
     * customizations using the new convention.
     *
     * @param string $template Template string containing {date}, {articles_context}, etc.
     * @param string $date     Briefing date.
     * @param array  $articles Harvested articles.
     * @param string $duration Duration option.
     * @return string Hydrated text.
     */
    public function hydrate_prompt( string $template, string $date, array $articles = [], string $duration = '' ): string {
        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        $specs = $this->get_duration_specs( $duration );

        $replacements = [
            '{date}'             => $date,
            '{articles_context}' => $this->format_articles_context( $articles ),
            '{sources_list}'     => $this->get_sources_list( $articles ),
            '{duration_text}'    => $specs['description'],
            '{word_budget}'      => (string) $specs['target_words'],
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
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
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

        // Host configuration (Issue #90: only host_count is needed now;
        // host names are no longer injected into the prompt).
        $host_count = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_briefing_host_count() : (int) get_option( 'presshub_ai_briefing_host_count', 2 );
        if ( $host_count < 1 || $host_count > 3 ) {
            $host_count = 2;
        }

        // Issue #90 — Active dialogue style (default_greek_chat |
        // bbc_broadcasting_standards | conversational_news_reporting).
        $style = class_exists( 'PressHub_AI_Settings_Storage' )
            ? PressHub_AI_Settings_Storage::get_podcast_style()
            : 'default_greek_chat';

        // 1. Base dialogue prompt & filter (system prompt defines persona, rules, speaker tags only)
        $base_prompt = '';
        if ( class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            if ( 1 === $host_count ) {
                $base_prompt = PressHub_AI_Settings_Storage::get_podcast_prompt_1( $style );
            } elseif ( 3 === $host_count ) {
                $base_prompt = PressHub_AI_Settings_Storage::get_podcast_prompt_3( $style );
            } else {
                $base_prompt = PressHub_AI_Settings_Storage::get_podcast_prompt_2( $style );
            }
        }


        $base_prompt = apply_filters( 'presshub_ai_podcast_producer_system_prompt', $base_prompt );

        // 2. Hydrate placeholders (Issue #90: no host-name args).
        $system_prompt = $this->hydrate_prompt( $base_prompt, $date, $articles, $duration );

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
        // Issue #90 - presenter_mention now uses generic [SPEAKER_N] labels
        // rather than the operator-configured host names, mirroring the system
        // prompt's generic-label convention.
        if ( 1 === $host_count ) {
            $presenter_mention = sprintf( __( 'The script should be delivered by the single presenter labelled [SPEAKER_1].', 'presshub-ai-editor' ) );
        } elseif ( 3 === $host_count ) {
            $presenter_mention = sprintf( __( 'The script should be delivered by the three presenters labelled [SPEAKER_1], [SPEAKER_2] and [SPEAKER_3].', 'presshub-ai-editor' ) );
        } else {
            $presenter_mention = sprintf( __( 'The script should be delivered by the two presenters labelled [SPEAKER_1] and [SPEAKER_2].', 'presshub-ai-editor' ) );
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
            || false !== stripos( $raw_script, 'TOPICSTART' )
            || false !== stripos( $raw_script, 'TOPIC:' )
        );

        if ( $has_explicit_topics ) {
            $lines = explode( "\n", $raw_script );
            $current_title = null;
            $current_topic_lines = [];

            foreach ( $lines as $line ) {
                $trimmed = trim( $line );

                // Match [TOPIC_START: Title] or <!-- TOPIC_START: Title --> or === TOPIC: Title === or ### TOPIC: Title or TOPICSTART: Title
                if ( preg_match( '/^(?:\[|\<\!\-\-|\={2,3}|\#{2,3})?\s*TOPIC(?:_?START)?\s*:\s*([^\]\>\=]+)(?:\]|\-\-\>|\={2,3})?/iu', $trimmed, $m ) ) {
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

                // Match [TOPIC_END] or <!-- TOPIC_END --> or TOPICEND]
                if ( preg_match( '/^(?:\[|\<\!\-\-)?\s*TOPIC_?END\s*(?:\]|\-\-\>)?/iu', $trimmed ) ) {
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
     * @param string $date             Briefing date (YYYY-MM-DD).
     * @param string $script           Dialogue script text.
     * @param string $context_mode     Optional — 'curated_briefing' or 'harvested_articles' (Issue #79).
     * @param int    $source_post_id   Optional source WP post ID (curated mode).
     * @param string $attempted_at     Optional ISO 8601 attempted timestamp.
     * @return bool True on success, false on failure.
     */
    public function save_script( string $date, string $script, string $context_mode = '', int $source_post_id = 0, string $attempted_at = '' ): bool {
        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
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

        // Issue #79 — persist execution lifecycle metadata for the Briefing
        // Hub stage card. Kept in a sibling JSON file (no WP post) so the
        // aggregator can rebuild "Curated Morning Briefing" vs "Direct
        // Harvested Articles" without parsing the script body. Schema is
        // additive and forward-compatible: missing/old files simply yield
        // null/empty values in the aggregator.
        $normalized_context = in_array( $context_mode, [ 'curated_briefing', 'harvested_articles' ], true ) ? $context_mode : '';
        $meta_path          = trailingslashit( $dir ) . 'podcast-script.meta.json';
        $sidecar_exists     = file_exists( $meta_path );
        // Persistence rule: write the sidecar only when (a) the caller
        // explicitly supplied a recognized context_mode (or non-zero source
        // post id), OR (b) no sidecar exists yet. This prevents a follow-up
        // call with a bogus context_mode from erasing a previously-persisted
        // valid mode while still allowing an existing-script re-save to
        // stamp completed_at and attempted_at on the first invocation.
        if ( $saved && ( '' !== $normalized_context || $source_post_id > 0 || ( '' !== (string) $attempted_at && ! $sidecar_exists ) ) ) {
            $meta = [
                'context_mode'   => $normalized_context,
                'source_post_id' => max( 0, $source_post_id ),
                'attempted_at'   => (string) $attempted_at,
                'completed_at'   => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
            ];
            @file_put_contents( $meta_path, wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
        }

        return $saved;
    }

    /**
     * Issue #79 — Read podcast-script lifecycle metadata (context mode,
     * source post ID, attempted/completed timestamps) previously written
     * by save_script(). Returns an empty array when no metadata file
     * exists (legacy runs).
     *
     * @param string $date Briefing date (YYYY-MM-DD).
     * @return array{context_mode:string,source_post_id:int,attempted_at:string,completed_at:string}
     */
    public function get_script_meta( string $date ): array {
        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        $defaults = [
            'context_mode'   => '',
            'source_post_id' => 0,
            'attempted_at'   => '',
            'completed_at'   => '',
        ];

        $harvester = new PressHub_AI_News_Harvester();
        $path      = trailingslashit( $harvester->get_snapshot_dir( $date ) ) . 'podcast-script.meta.json';

        if ( ! file_exists( $path ) ) {
            return $defaults;
        }

        $content = @file_get_contents( $path );
        if ( false === $content || '' === trim( $content ) ) {
            return $defaults;
        }

        $decoded = json_decode( $content, true );
        if ( ! is_array( $decoded ) ) {
            return $defaults;
        }

        $mode = isset( $decoded['context_mode'] ) && in_array( $decoded['context_mode'], [ 'curated_briefing', 'harvested_articles' ], true )
            ? $decoded['context_mode']
            : '';

        return [
            'context_mode'   => $mode,
            'source_post_id' => isset( $decoded['source_post_id'] ) ? (int) $decoded['source_post_id'] : 0,
            'attempted_at'   => isset( $decoded['attempted_at'] ) ? (string) $decoded['attempted_at'] : '',
            'completed_at'   => isset( $decoded['completed_at'] ) ? (string) $decoded['completed_at'] : '',
        ];
    }

    /**
     * Load podcast dialogue script from briefing storage directory.
     *
     * @param string $date Briefing date (YYYY-MM-DD).
     * @return string|null Script content or null if not found.
     */
    public function get_script( string $date ): ?string {
        if ( empty( $date ) ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
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
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
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

        // Issue #79 — record the timestamp when script generation was *attempted*
        // so the Briefing Hub stage card can later show "Attempted at HH:MM:SS"
        // even if the LLM call later fails. Mirrors the spec's request for
        // start-and-finish timestamps per pipeline stage.
        $script_attempted_at = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

        // 5. Persist script to daily briefing directory
        // Issue #79 — pass through context mode + source post id + attempted
        // timestamp so get_script_meta() can later rebuild "Curated Morning
        // Briefing vs Direct Harvested Articles" without parsing the body.
        // We deliberately avoid importing PressHub_AI_Briefing_Admin here
        // (cross-class include would risk a circular require chain). Inline
        // a small get_posts() lookup mirroring its find_briefing_post() logic.
        $briefing_source_post_id = 0;
        if ( 'curated_briefing' === $context_mode && function_exists( 'get_posts' ) ) {
            $briefing_posts = get_posts( [
                'post_type'      => 'post',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'meta_query'     => [
                    [ 'key' => '_presshub_briefing_date', 'value' => $date ],
                    [ 'key' => '_presshub_briefing_type', 'value' => 'text' ],
                ],
            ] );
            if ( ! empty( $briefing_posts ) ) {
                $briefing_source_post_id = (int) $briefing_posts[0]->ID;
            }
        }
        $this->save_script( $date, $response, $context_mode, $briefing_source_post_id, $script_attempted_at );

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
