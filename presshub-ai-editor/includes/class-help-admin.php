<?php
/**
 * PressHub_AI_Help_Admin - PressHub → Help.
 *
 * A read-only page that explains the editorial knowledge features (editorial
 * agenda, policy fiches, people fiches, editorial memory): what each one does,
 * which AI tasks receive it, how it changes the output, user stories, when to
 * use the agenda and when a policy fiche, and the limits of each.
 *
 * Accuracy rules:
 *  - The "which task gets what" table is built from the live budgets
 *    (PressHub_AI_Editorial_Agenda::budget_for(), PressHub_AI_Stakeholders::
 *    budget_for(), PressHub_AI_Policy_Fiches::budget_for() / parts_for(), the
 *    memory budget getters), so it follows Settings → Editorial knowledge.
 *  - The "what the AI receives" examples are produced by the same render()
 *    functions that build the real prompt blocks, from sample data that is
 *    never stored.
 *  - The page writes nothing and calls no AI provider.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Help_Admin {

    const SLUG = 'presshub-ai-help';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /** Editors and administrators, like the other PressHub pages. */
    public static function capability(): string {
        return PressHub_Capabilities::manage();
    }

    public static function url( string $anchor = '' ): string {
        $url = PressHub_Admin_Menu::url( self::SLUG );
        return '' !== $anchor ? $url . '#' . rawurlencode( $anchor ) : $url;
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            PressHub_Admin_Menu::PARENT,
            PressHub_Admin_Menu::page_title( __( 'Help', 'presshub-ai-editor' ) ),
            __( 'Help', 'presshub-ai-editor' ),
            self::capability(),
            self::SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    public function enqueue_assets( $hook ): void {
        if ( ! PressHub_Admin_Menu::is_page_hook( (string) $hook, self::SLUG ) || ! current_user_can( self::capability() ) ) {
            return;
        }
        wp_enqueue_style( 'presshub-ai-help', PRESSHUB_AI_URL . 'assets/css/help.css', [], PRESSHUB_AI_VERSION );
    }

    public static function render_page(): void {
        if ( ! current_user_can( self::capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to view the PressHub help.', 'presshub-ai-editor' ) );
        }
        echo self::build_page(); // phpcs:ignore WordPress.Security.EscapeOutput -- every part is escaped in build_page().
    }

    // ------------------------------------------------------------------
    // Page
    // ------------------------------------------------------------------

    /** The whole page as HTML (escaped). */
    public static function build_page(): string {
        $html  = '<div class="wrap presshub-help" id="presshub-help-page">';
        $html .= '<h1>' . esc_html( PressHub_Admin_Menu::page_title( __( 'Help', 'presshub-ai-editor' ) ) ) . '</h1>';
        $html .= self::p( __( 'How the editorial knowledge features work: the editorial agenda, policy fiches, people fiches and editorial memory. Each one adds text to the instructions PressHub sends to the AI. This page explains what each one adds, to which AI tasks, how it changes what PressHub writes, and where it stops working.', 'presshub-ai-editor' ), 'presshub-help-lead' );
        $html .= self::toc();
        $html .= self::section_status();
        $html .= self::section_overview();
        $html .= self::section_agenda();
        $html .= self::section_policies();
        $html .= self::section_agenda_vs_policies();
        $html .= self::section_people();
        $html .= self::section_memory();
        $html .= self::section_limitations();
        $html .= self::section_where();
        return $html . '</div>';
    }

    /** Section anchors and titles, in page order. */
    public static function sections(): array {
        return [
            'status'     => __( 'Right now on this site', 'presshub-ai-editor' ),
            'overview'   => __( 'How the features fit together', 'presshub-ai-editor' ),
            'agenda'     => __( 'Editorial agenda', 'presshub-ai-editor' ),
            'policies'   => __( 'Policy fiches', 'presshub-ai-editor' ),
            'choose'     => __( 'Agenda or policy fiche?', 'presshub-ai-editor' ),
            'people'     => __( 'People fiches', 'presshub-ai-editor' ),
            'memory'     => __( 'Editorial memory', 'presshub-ai-editor' ),
            'limits'     => __( 'Limitations', 'presshub-ai-editor' ),
            'where'      => __( 'Where to change things and check what was sent', 'presshub-ai-editor' ),
        ];
    }

    private static function toc(): string {
        $items = '';
        foreach ( self::sections() as $id => $label ) {
            $items .= '<li><a href="#presshub-help-' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a></li>';
        }
        return '<nav class="presshub-help-toc" aria-label="' . esc_attr__( 'On this page', 'presshub-ai-editor' ) . '"><h2>' . esc_html__( 'On this page', 'presshub-ai-editor' ) . '</h2><ol>' . $items . '</ol></nav>';
    }

    // ------------------------------------------------------------------
    // Sections
    // ------------------------------------------------------------------

    private static function section_status(): string {
        $rows = [];

        $agenda = PressHub_AI_Editorial_Agenda::get();
        $active = count( PressHub_AI_Editorial_Agenda::active_themes( $agenda ) );
        $rows[] = [
            self::link( PressHub_AI_Fiches_Admin::agenda_url(), __( 'Editorial agenda', 'presshub-ai-editor' ) ),
            $agenda['version'] > 0
                /* translators: 1: version number, 2: number of themes */
                ? esc_html( sprintf( _n( 'Version %1$d, %2$d theme that has not expired.', 'Version %1$d, %2$d themes that have not expired.', $active, 'presshub-ai-editor' ), (int) $agenda['version'], $active ) )
                : esc_html__( 'Not written yet, so no AI call gets an agenda.', 'presshub-ai-editor' ),
        ];

        $fiches    = PressHub_AI_Policy_Fiches::all();
        $published = count( array_filter( $fiches, static function ( $f ) { return 'published' === $f['status']; } ) );
        $rows[]    = [
            self::link( PressHub_AI_Policy_Fiches::page_url(), __( 'Policy fiches', 'presshub-ai-editor' ) ),
            /* translators: 1: published fiches, 2: draft fiches */
            esc_html( sprintf( __( '%1$d published (sent to the AI), %2$d drafts (never sent).', 'presshub-ai-editor' ), $published, count( $fiches ) - $published ) )
                . ( PressHub_AI_Policy_Fiches::is_enabled() ? '' : ' ' . self::off_badge() ),
        ];

        $people   = PressHub_AI_Stakeholders::all();
        $locked   = count( array_filter( $people, static function ( $f ) { return $f['locked']; } ) );
        $excluded = count( array_filter( $people, static function ( $f ) { return $f['excluded']; } ) );
        $rows[]   = [
            self::link( PressHub_AI_Fiches_Admin::people_url(), __( 'People fiches', 'presshub-ai-editor' ) ),
            /* translators: 1: fiches, 2: locked, 3: excluded */
            esc_html( sprintf( __( '%1$d fiches, %2$d locked, %3$d excluded.', 'presshub-ai-editor' ), count( $people ), $locked, $excluded ) )
                . ( PressHub_AI_Stakeholders::is_enabled() ? '' : ' ' . self::off_badge() ),
        ];

        $rows[] = [
            self::link( PressHub_Admin_Menu::url( 'presshub-ai-memory' ), __( 'Editorial memory', 'presshub-ai-editor' ) ),
            PressHub_AI_Settings_Storage::get_memory_enabled()
                /* translators: 1: recent days, 2: background days */
                ? esc_html( sprintf( __( 'On. Recent window %1$d days of daily digests, background window %2$d days of weekly rollups.', 'presshub-ai-editor' ), PressHub_AI_Settings_Storage::get_memory_daily_days(), PressHub_AI_Settings_Storage::get_memory_background_days() ) )
                : self::off_badge() . ' ' . esc_html__( 'People and policy fiches are off too, because they need memory on.', 'presshub-ai-editor' ),
        ];

        $html  = self::p( __( 'A quick look at what each feature holds today. Follow a link to open its page.', 'presshub-ai-editor' ) );
        $html .= self::table( [ __( 'Feature', 'presshub-ai-editor' ), __( 'State', 'presshub-ai-editor' ) ], $rows, true );
        return self::section( 'status', $html );
    }

    private static function section_overview(): string {
        $html  = self::p( __( 'Every text that PressHub writes with AI starts from a prompt: the instructions for that task (for example the daily briefing prompt) plus the material (the harvested articles, the draft, the post). The four features add short blocks of editorial knowledge to the instructions, in this order:', 'presshub-ai-editor' ) );
        $html .= self::ol( [
            __( 'Editorial memory, for the daily briefing and the podcast script only: what PressHub published in recent days, so today continues the story instead of starting it again.', 'presshub-ai-editor' ),
            __( 'Editorial agenda: the themes you want followed this week and your standing guidance. It goes to every editorial AI task.', 'presshub-ai-editor' ),
            __( 'People fiches: for the people named in the material, their current role, party and latest stated positions.', 'presshub-ai-editor' ),
            __( 'Policy fiches: for the policy files mentioned in the material, the newsroom\'s principles, framing rules, checked facts and the claims to watch.', 'presshub-ai-editor' ),
        ] );
        $html .= self::p( __( 'The articles still decide what happened today. The editorial knowledge decides how it is selected, ordered, titled and framed, and what the AI must not repeat as fact. Each block has its own size limit per task (in characters). When a block is longer than its limit, its least important part is dropped first. Memory is also shrunk when the articles alone come close to the model\'s limit; the other blocks only follow their own limit.', 'presshub-ai-editor' ) );
        $html .= self::h3( __( 'Which AI task gets what', 'presshub-ai-editor' ) );
        $html .= self::p( __( 'Read from the current settings. A number is the most characters of that block the task can receive; a dash means the task never gets it.', 'presshub-ai-editor' ) );
        $html .= self::table(
            [ __( 'AI task', 'presshub-ai-editor' ), __( 'Memory', 'presshub-ai-editor' ), __( 'Agenda', 'presshub-ai-editor' ), __( 'People', 'presshub-ai-editor' ), __( 'Policies (parts sent)', 'presshub-ai-editor' ) ],
            self::matrix_rows(),
            true
        );
        $html .= self::p( __( 'Never receive any of it: the AI calls that only extract facts or classify (building memory digests and weekly rollups, extracting people and policy updates, intent detection in the co-pilot, reading uploaded files, the Trending in Cyprus summary, image and speech generation). They must report what the text says, not the newsroom line.', 'presshub-ai-editor' ) );
        return self::section( 'overview', $html );
    }

    /**
     * The AI tasks in the table: the action each call sets (exactly as in
     * set_action() / current_action) => label. HelpPageTest checks that every
     * key is a real action in the code.
     */
    public static function tasks(): array {
        return [
            'briefing_curation'     => __( 'Daily briefing text (morning and noon)', 'presshub-ai-editor' ),
            'podcast_script'        => __( 'Podcast script', 'presshub-ai-editor' ),
            'coauthor_draft'        => __( 'Co-author draft in the post editor', 'presshub-ai-editor' ),
            'copilot_chat'          => __( 'Co-pilot chat in the post editor', 'presshub-ai-editor' ),
            'coauthor_scorecard'    => __( 'QA gate: editorial and legal review', 'presshub-ai-editor' ),
            'seo_review'            => __( 'QA gate: SEO check', 'presshub-ai-editor' ),
            'generate_post_seo'     => __( 'SEO tags, meta description and focus keyphrase', 'presshub-ai-editor' ),
            'social_copy_facebook'  => __( 'Social post copy (X, Facebook, Instagram, TikTok)', 'presshub-ai-editor' ),
            'social_tiktok_shorts'  => __( 'TikTok short and Reel plan', 'presshub-ai-editor' ),
            'generate_image_prompt' => __( 'Featured image prompt', 'presshub-ai-editor' ),
        ];
    }

    /** Rows of the "which task gets what" table. */
    public static function matrix_rows(): array {
        $tasks     = self::tasks();
        $memory_on = PressHub_AI_Settings_Storage::get_memory_enabled();
        $memory    = [
            'briefing_curation' => PressHub_AI_Settings_Storage::get_memory_briefing_budget_chars(),
            'podcast_script'    => PressHub_AI_Settings_Storage::get_memory_podcast_budget_chars(),
        ];
        $rows = [];
        foreach ( $tasks as $action => $label ) {
            $policy = PressHub_AI_Policy_Fiches::budget_for( $action );
            $rows[] = [
                esc_html( $label ),
                esc_html( isset( $memory[ $action ] ) && $memory_on ? self::chars( $memory[ $action ] ) : self::chars( 0 ) ),
                esc_html( self::chars( PressHub_AI_Editorial_Agenda::budget_for( $action ) ) ),
                esc_html( self::chars( PressHub_AI_Stakeholders::is_enabled() ? PressHub_AI_Stakeholders::budget_for( $action ) : 0 ) ),
                esc_html( $policy > 0 && PressHub_AI_Policy_Fiches::is_enabled()
                    ? self::chars( $policy ) . ' (' . self::parts_label( PressHub_AI_Policy_Fiches::parts_for( $action ) ) . ')'
                    : self::chars( 0 ) ),
            ];
        }
        return $rows;
    }

    private static function section_agenda(): string {
        $html  = self::p( __( 'The agenda is one short page that you keep up to date: up to 12 running themes, each with a priority from 1 to 5, optional framing notes and an optional end date, plus standing guidance for the whole newsroom (up to 2,000 characters). The last 20 saved versions are kept, so you can see what changed.', 'presshub-ai-editor' ) );
        $html .= self::h3( __( 'How it is used', 'presshub-ai-editor' ) );
        $html .= self::ul( [
            __( 'It is added to the instructions of every AI task that has an agenda number in the table above: the briefing, podcast, co-author, co-pilot, social copy, shorts, the QA gate\'s SEO check and the image prompt. It is not matched against the material: it goes to these tasks whether or not today\'s articles mention its themes. The SEO tags and meta description, and the QA gate\'s editorial and legal review, do not get it.', 'presshub-ai-editor' ),
            sprintf(
                /* translators: %d: characters */
                __( 'Themes are sent highest priority first. A theme past its end date is no longer sent. When a task has little room (social copy gets %d characters), the lowest-priority themes are dropped first and the standing guidance is shortened last.', 'presshub-ai-editor' ),
                PressHub_AI_Editorial_Agenda::budget_for( 'social_copy_facebook' )
            ),
            __( 'The AI is told to use the agenda for choosing, ordering and framing stories, and not to invent news about a theme that is not in the material.', 'presshub-ai-editor' ),
            __( 'After each weekly memory rollup, PressHub suggests new themes from the stories that are still open. A suggestion waits on the agenda page until you accept or dismiss it; it is never applied on its own.', 'presshub-ai-editor' ),
            __( 'It does not depend on the editorial memory switch: an agenda you saved is sent even when memory is off. To stop it, remove its themes and guidance, or set a task\'s agenda budget to 0.', 'presshub-ai-editor' ),
        ] );
        $html .= self::h3( __( 'What the AI receives (example)', 'presshub-ai-editor' ) );
        $html .= self::p( __( 'Built by the real agenda code from a sample agenda, for the daily briefing. The sample is not saved.', 'presshub-ai-editor' ) );
        $html .= self::pre( PressHub_AI_Editorial_Agenda::render( self::sample_agenda(), PressHub_AI_Editorial_Agenda::budget_for( 'briefing_curation' ) ) );
        $html .= self::h3( __( 'How it changes the output', 'presshub-ai-editor' ) );
        $html .= self::effect(
            __( 'Without the agenda, the briefing orders the day\'s stories by what the articles stress most. A short statement on the Cyprus talks may sit in the middle, worded like the press release.', 'presshub-ai-editor' ),
            __( 'With the sample agenda, the talks story moves up because its priority is 5, the text says at which stage the talks are, and statements are attributed («σύμφωνα με τον κυβερνητικό εκπρόσωπο»). Headlines stay descriptive because of the standing guidance. If there is no talks story in today\'s articles, nothing about the talks is added.', 'presshub-ai-editor' )
        );
        $html .= self::h3( __( 'User stories', 'presshub-ai-editor' ) );
        $html .= self::story(
            __( 'Election fortnight', 'presshub-ai-editor' ),
            __( 'As editor-in-chief, I want every briefing, podcast and social post during the campaign to give the parliamentary elections priority and treat all parties alike.', 'presshub-ai-editor' ),
            __( 'Add a theme "Βουλευτικές εκλογές" with priority 5, framing notes ("ίση μεταχείριση των κομμάτων· δημοσκοπήσεις πάντα με εταιρεία, δείγμα και ημερομηνία") and an end date the day after the vote. It reaches every editorial task at once and stops by itself when it expires.', 'presshub-ai-editor' )
        );
        $html .= self::story(
            __( 'House style for the whole newsroom', 'presshub-ai-editor' ),
            __( 'As editor, I want headlines without exclamation marks and amounts always with their source, in everything PressHub writes.', 'presshub-ai-editor' ),
            __( 'Put it in the standing guidance. Use the guidance for rules that apply to every story; keep rules about one subject in that subject\'s policy fiche.', 'presshub-ai-editor' )
        );
        $html .= self::story(
            __( 'Keeping a slow story alive', 'presshub-ai-editor' ),
            __( 'As editor, I want the housing scheme to be picked whenever there is any news about it this month, even a small item.', 'presshub-ai-editor' ),
            __( 'Add it as a theme with priority 4 and an end date. The curator gives it more weight when choosing stories. For how housing must be framed (which numbers, what to attribute), write a policy fiche as well.', 'presshub-ai-editor' )
        );
        $html .= self::story(
            __( 'Monday clean-up', 'presshub-ai-editor' ),
            __( 'As editor, I want the agenda to follow the stories that are actually running.', 'presshub-ai-editor' ),
            __( 'After the weekly rollup, open the agenda page, review the suggested themes (new open stories are added after yours, developing stories at priority 4), accept or dismiss, then remove themes that are over.', 'presshub-ai-editor' )
        );
        $html .= self::tips( [
            __( 'Keep it short. A long agenda is cut for the small tasks, and it is sent on every call, so it costs tokens every time.', 'presshub-ai-editor' ),
            __( 'Give themes an end date. A forgotten theme keeps pushing the same story up for weeks.', 'presshub-ai-editor' ),
            __( 'Write the notes in Greek, in short instructions, like the prompts.', 'presshub-ai-editor' ),
        ] );
        return self::section( 'agenda', $html );
    }

    private static function section_policies(): string {
        $html  = self::p( __( 'A policy fiche holds the newsroom\'s line on one policy file, such as the Cyprus problem, the pension reform or housing. It has two halves:', 'presshub-ai-editor' ) );
        $html .= self::ul( [
            __( 'The core, which is yours and is never rewritten automatically: title, aliases (the words the news uses for the file), principles (the questions coverage must always ask), framing rules (wording that must be attributed, words to use or avoid) and confirmed facts with a link to their source.', 'presshub-ai-editor' ),
            __( 'The running record, which PressHub adds to after each published briefing: a dated timeline, a claims register (unchecked, contested, confirmed, false), the actors and open questions. PressHub may mark a claim contested; only you mark a claim confirmed or false. Changes PressHub would make to the core wait as suggestions for you to accept.', 'presshub-ai-editor' ),
        ] );
        $html .= self::h3( __( 'How it is used', 'presshub-ai-editor' ) );
        $html .= self::ul( [
            __( 'A fiche is used only when it is published and its title or one of its aliases appears in the material of the task. Greek word endings are tolerated (συντάξεις also matches συντάξεων), but a single word shorter than 6 letters never matches on its own. Drafts are never sent.', 'presshub-ai-editor' ),
            __( 'At most 3 fiches go into one task, the most mentioned first. Each task gets the parts it can use (see the table above): the briefing, co-author and co-pilot get everything; the podcast gets principles, framing and claims; the QA gate gets framing and claims and is asked to lower the score when the text breaks them; social copy and shorts get the framing only.', 'presshub-ai-editor' ),
            __( 'When a fiche does not fit a task\'s budget, it is sent with fewer parts (open questions and facts go first), and left out if even principles and framing do not fit. The policy page warns you when a fiche is too long for the podcast or social copy.', 'presshub-ai-editor' ),
            __( 'False and contested claims are sent before unchecked ones; confirmed claims are not sent as claims. The AI is told to attribute contested and unchecked claims, give the counter-point, and never repeat a false claim as true.', 'presshub-ai-editor' ),
            __( 'The Daily Briefing Hub lists the policy files in the day\'s article pool with their claims to watch, and the claims the last briefing repeated as fact.', 'presshub-ai-editor' ),
            __( 'A topic that keeps coming back without a fiche (in 3 editions) becomes a draft fiche for you to review. The "Draft fiches from the agenda and memory" button writes first drafts too. Both stay drafts until you publish them.', 'presshub-ai-editor' ),
        ] );
        $html .= self::h3( __( 'What the AI receives (example)', 'presshub-ai-editor' ) );
        $html .= self::p( __( 'Built by the real policy code from the worked example on the Policies page (pension reform; the parts in brackets are placeholders), first for the daily briefing, then for social copy. The example is not saved.', 'presshub-ai-editor' ) );
        $html .= self::pre( self::sample_policy_block( 'briefing_curation' ) );
        $social_room = self::policy_room( 'social_copy_facebook' );
        $social_full = self::sample_policy_block( 'social_copy_facebook' );
        if ( '' !== $social_full ) {
            $html .= self::p( sprintf(
                /* translators: 1: characters left for a fiche in social copy, 2: length of the example's framing */
                __( 'Social copy has room for about %1$d characters of fiche after the fixed instructions, and the example\'s framing is %2$d characters, so it is sent in full:', 'presshub-ai-editor' ),
                $social_room,
                self::strlen_u( (string) PressHub_AI_Policy_Fiches::example_fiche()['framing'] )
            ) );
            $html .= self::pre( $social_full );
        } elseif ( '' !== ( $social_first = self::sample_policy_block( 'social_copy_facebook', true ) ) ) {
            $html .= self::p( sprintf(
                /* translators: 1: characters left for a fiche in social copy, 2: length of the example's framing */
                __( 'Social copy has room for about %1$d characters of fiche after the fixed instructions. The example\'s framing is %2$d characters, so the full example would be left out of social copy. Below, the same fiche with only its first framing rule:', 'presshub-ai-editor' ),
                $social_room,
                self::strlen_u( (string) PressHub_AI_Policy_Fiches::example_fiche()['framing'] )
            ) );
            $html .= self::pre( $social_first );
        } else {
            $html .= self::p( sprintf(
                /* translators: %d: characters left for a fiche in social copy */
                __( 'Social copy has room for about %d characters of fiche after the fixed instructions, too little even for the example\'s first framing rule, so social copy would get no policy fiche:', 'presshub-ai-editor' ),
                $social_room
            ) );
            $html .= self::pre( '' );
        }
        $html .= self::h3( __( 'How it changes the output', 'presshub-ai-editor' ) );
        $html .= self::effect(
            __( 'Without the fiche, an article quoting the government that "Parliament is blocking the pension increase" can come out as «Η Βουλή μπλοκάρει την αύξηση των συντάξεων», in the newsroom\'s own voice, with "100 euro" and no limits.', 'presshub-ai-editor' ),
            __( 'With the fiche, the same material comes out as «Σύμφωνα με την κυβέρνηση, η Βουλή καθυστερεί την αύξηση· η αντιπολίτευση απαντά ότι δεν έχει κατατεθεί νομοσχέδιο», with «έως 100 ευρώ» and who it applies to. In the QA gate, a draft that states the claim as fact gets a lower score and the feedback names the claim.', 'presshub-ai-editor' )
        );
        $html .= self::h3( __( 'User stories', 'presshub-ai-editor' ) );
        $html .= self::story(
            __( 'A disputed claim keeps coming back', 'presshub-ai-editor' ),
            __( 'As editor, I want PressHub never to present the government\'s claim about the pension increase as fact, in any briefing, draft or post.', 'presshub-ai-editor' ),
            __( 'Record the claim in the pension fiche\'s claims register with who makes it and the counter-point, status contested. Every task whose material mentions pensions gets it; the Hub shows it when the claim is in the day\'s pool and when a briefing repeated it.', 'presshub-ai-editor' )
        );
        $html .= self::story(
            __( 'Sensitive wording on the Cyprus problem', 'presshub-ai-editor' ),
            __( 'As editor, I want the terms we use for the Cyprus problem to be the same in every text, whoever or whatever writes it.', 'presshub-ai-editor' ),
            sprintf(
                /* translators: %d: characters */
                __( 'Write the terms as framing rules, one per line, and add aliases the news uses (Κυπριακό, συνομιλίες, Cyprus talks). Framing is the one part that every task with policy fiches gets, even social copy, but keep it short: social copy has room for about %d characters of title and framing.', 'presshub-ai-editor' ),
                self::policy_room( 'social_copy_facebook' )
            )
        );
        $html .= self::story(
            __( 'Background numbers you have checked', 'presshub-ai-editor' ),
            __( 'As a journalist using the co-author, I want the draft to use the official figures for the housing scheme, not the ones in a press release.', 'presshub-ai-editor' ),
            __( 'Add the figures as confirmed facts with their source link. The co-author and the briefing may use them as background; anything not checked stays in the claims register with who said it.', 'presshub-ai-editor' )
        );
        $html .= self::tips( [
            __( 'Put the most important rule first. When room is short a fiche falls back to its principles and framing, and social copy and shorts get the framing only.', 'presshub-ai-editor' ),
            __( 'Ask for attribution and the other side, not for a conclusion. A fiche that asks the AI to take a side makes everything PressHub writes less credible.', 'presshub-ai-editor' ),
            __( 'Keep today\'s news out of the core. A date or "yesterday" in the principles goes stale; it belongs in the timeline or the claims.', 'presshub-ai-editor' ),
            __( 'Lock a fiche when nothing should be added to it automatically.', 'presshub-ai-editor' ),
        ] );
        return self::section( 'policies', $html );
    }

    private static function section_agenda_vs_policies(): string {
        $html  = self::p( __( 'Both change how stories are told, but they reach the AI differently. The agenda is sent to every editorial task, always. A policy fiche is sent only when its subject is in the material, and then with much more detail.', 'presshub-ai-editor' ) );
        $html .= self::table(
            [ '', __( 'Editorial agenda', 'presshub-ai-editor' ), __( 'Policy fiche', 'presshub-ai-editor' ) ],
            [
                [ esc_html__( 'Answers', 'presshub-ai-editor' ), esc_html__( 'What matters this week, and in which order?', 'presshub-ai-editor' ), esc_html__( 'How do we tell this subject, every time it comes up?', 'presshub-ai-editor' ) ],
                [ esc_html__( 'Sent', 'presshub-ai-editor' ), esc_html__( 'To every editorial task, whether or not the subject is in the material.', 'presshub-ai-editor' ), esc_html__( 'Only when its title or an alias is in the material, at most 3 per task.', 'presshub-ai-editor' ) ],
                [ esc_html__( 'Changes', 'presshub-ai-editor' ), esc_html__( 'Selection, order and emphasis of stories; general style.', 'presshub-ai-editor' ), esc_html__( 'Wording, attribution, background facts, claims to check, questions to ask.', 'presshub-ai-editor' ) ],
                [ esc_html__( 'Lifetime', 'presshub-ai-editor' ), esc_html__( 'Days or weeks; themes expire.', 'presshub-ai-editor' ), esc_html__( 'Months; the core should still hold in a month.', 'presshub-ai-editor' ) ],
                [ esc_html__( 'Size', 'presshub-ai-editor' ), esc_html__( 'Short: one line and a note per theme.', 'presshub-ai-editor' ), esc_html__( 'Detailed: principles, rules, facts, claims, questions.', 'presshub-ai-editor' ) ],
                [ esc_html__( 'Updated by PressHub', 'presshub-ai-editor' ), esc_html__( 'Never; it only suggests themes after the weekly rollup.', 'presshub-ai-editor' ), esc_html__( 'The running record after each briefing; the core never, only suggestions.', 'presshub-ai-editor' ) ],
            ],
            true
        );
        $html .= self::h3( __( 'Rules of thumb', 'presshub-ai-editor' ) );
        $html .= self::ul( [
            __( 'Use the agenda to say "cover this more, or first, now". Use a policy fiche to say "when you cover this, do it like this".', 'presshub-ai-editor' ),
            __( 'A rule that applies to every story (headline style, how to cite polls) goes in the agenda\'s standing guidance. A rule about one subject goes in that subject\'s fiche, so it does not take room in every other call.', 'presshub-ai-editor' ),
            __( 'A disputed claim, a figure or a source link always goes in a policy fiche. The agenda has no claims register and no facts.', 'presshub-ai-editor' ),
            __( 'For a big running story, use both: an agenda theme with priority 5 and an end date to push it up, and a policy fiche for the wording. Keep the agenda note to one line and let the fiche carry the detail.', 'presshub-ai-editor' ),
            sprintf(
                /* translators: 1: agenda characters for social copy, 2: characters of fiche text left in social copy */
                __( 'Social copy gets only a short agenda (%1$d characters) and only the framing of a fiche, with room for about %2$d characters of title and framing. Rules that must reach social posts belong in the agenda\'s standing guidance or, kept short, in the fiche\'s framing. The SEO tags and meta description get neither.', 'presshub-ai-editor' ),
                PressHub_AI_Editorial_Agenda::budget_for( 'social_copy_facebook' ),
                self::policy_room( 'social_copy_facebook' )
            ),
        ] );
        return self::section( 'choose', $html );
    }

    private static function section_people(): string {
        $html  = self::p( __( 'A people fiche holds what PressHub knows about one person in the news: the name in Greek and English, other names they go by (aliases), role, party, a one-line bio and up to 10 dated positions they stated, with a link to the briefing where each appeared.', 'presshub-ai-editor' ) );
        $html .= self::h3( __( 'How fiches are built', 'presshub-ai-editor' ) );
        $html .= self::ul( [
            __( 'About a minute after the memory digest of a published briefing is built, one AI call reads the briefing and lists the people in it with their role, party and the positions they stated (up to 15 people per briefing). PressHub merges them into the fiches by name, English name or alias.', 'presshub-ai-editor' ),
            __( 'A person needs a first name and a surname to get a fiche. Only the people in PressHub\'s own published briefings are collected, not every article on the site.', 'presshub-ai-editor' ),
            __( 'Your edits win: an edited fiche keeps its name, role, party and bio when new briefings come in (new positions are still added); a locked fiche is never changed automatically; an excluded fiche is never sent to the AI. You can merge two fiches of the same person.', 'presshub-ai-editor' ),
            __( 'PressHub keeps up to 250 fiches. When there are more, the least recently seen fiches that nobody edited or locked are dropped first.', 'presshub-ai-editor' ),
        ] );
        $html .= self::h3( __( 'How fiches are used', 'presshub-ai-editor' ) );
        $html .= self::ul( [
            __( 'By default only the daily briefing and the podcast script get people fiches (see the table above).', 'presshub-ai-editor' ),
            __( 'Only the people named in that task\'s material are sent, the most mentioned first, at most 12. Greek case endings are tolerated (Χριστοδουλίδης also matches Χριστοδουλίδη).', 'presshub-ai-editor' ),
            __( 'Each person is sent with role, party, bio and their 3 most recent positions; when room runs out, just the name and role. The AI is told to use them for correct titles and to note when someone changes position, and that old positions are not today\'s news.', 'presshub-ai-editor' ),
        ] );
        $html .= self::h3( __( 'What the AI receives (example)', 'presshub-ai-editor' ) );
        $html .= self::p( __( 'Built by the real people code from a fictional person. The sample is not saved.', 'presshub-ai-editor' ) );
        $html .= self::pre( self::sample_people_block() );
        $html .= self::h3( __( 'How it changes the output', 'presshub-ai-editor' ) );
        $html .= self::effect(
            __( 'Without the fiche, an article that says only «η κ. Ιωάννου» leaves the AI to guess her role, or to copy an old title from another article.', 'presshub-ai-editor' ),
            __( 'With the fiche, the briefing writes «η υπουργός Εργασίας Μαρία Ιωάννου». If today she argues against something she supported last month, the AI can say she has changed position, with the earlier date, instead of presenting both as new.', 'presshub-ai-editor' )
        );
        $html .= self::h3( __( 'User stories', 'presshub-ai-editor' ) );
        $html .= self::story(
            __( 'Cabinet reshuffle', 'presshub-ai-editor' ),
            __( 'As editor, I want the new ministers to get their new titles from the first briefing after the reshuffle.', 'presshub-ai-editor' ),
            __( 'Open the People page, correct the role of each person who moved and save. The fiche is now marked edited, so later briefings that still use the old title in their source articles cannot change it back.', 'presshub-ai-editor' )
        );
        $html .= self::story(
            __( 'Tracking a change of position', 'presshub-ai-editor' ),
            __( 'As a podcast producer, I want the hosts to point out when a party leader says the opposite of what they said a few weeks ago.', 'presshub-ai-editor' ),
            __( 'Nothing to do: positions are added from each briefing with their date, and the podcast gets the 3 latest. Check the fiche if the AI mixed up two topics, and fix the position text.', 'presshub-ai-editor' )
        );
        $html .= self::story(
            __( 'Two people, one name', 'presshub-ai-editor' ),
            __( 'As editor, I want the AI not to give a footballer the positions of a politician with the same surname.', 'presshub-ai-editor' ),
            __( 'Edit the fiche to keep the full name and remove the short alias that matches both. If the fiche is wrong beyond repair or is about a private person, exclude it so it is never sent.', 'presshub-ai-editor' )
        );
        $html .= self::tips( [
            __( 'Add the English spelling and common short forms as aliases, so the fiche matches English-language sources too.', 'presshub-ai-editor' ),
            __( 'Lock the fiches of the people you curate by hand (the President, party leaders) once they are right.', 'presshub-ai-editor' ),
        ] );
        return self::section( 'people', $html );
    }

    private static function section_memory(): string {
        $daily  = PressHub_AI_Settings_Storage::get_memory_daily_days();
        $bg     = PressHub_AI_Settings_Storage::get_memory_background_days();
        $html   = self::p( __( 'Editorial memory is what PressHub remembers of its own coverage. It lets the briefing and the podcast continue a running story ("following yesterday\'s announcement…") instead of introducing it from scratch every morning.', 'presshub-ai-editor' ) );
        $html  .= self::h3( __( 'How memory is built', 'presshub-ai-editor' ) );
        $html  .= self::ul( [
            __( 'About 30 seconds after a briefing text post is created or updated, one AI call turns it into a daily digest: the day\'s stories, each with a stable key, a title, a one- or two-sentence development and a status (new, developing or resolved). It sees the last 7 days\' story keys, so a running story keeps its key.', 'presshub-ai-editor' ),
            __( 'When a podcast script is saved, its opening and closing lines are stored in that day\'s digest, without another AI call.', 'presshub-ai-editor' ),
            __( 'Once a week, each completed week with digests gets a weekly rollup: a short narrative of the week and its stories (new, developing and resolved), built from that week\'s digests and the previous week\'s narrative, for continuity. Agenda suggestions use only its stories that are not resolved.', 'presshub-ai-editor' ),
            __( 'If the AI returns something invalid twice, the digest is marked failed and the Hub shows a notice with a retry button. Nothing half-built is stored.', 'presshub-ai-editor' ),
        ] );
        $html  .= self::h3( __( 'How memory is used', 'presshub-ai-editor' ) );
        $html  .= self::ul( [
            __( 'Only the daily briefing and the podcast script get memory.', 'presshub-ai-editor' ),
            /* translators: %d: days */
            sprintf( __( 'Recent: the daily digests of the last %d days, newest first.', 'presshub-ai-editor' ), $daily ),
            /* translators: %d: days */
            sprintf( __( 'Background: weekly rollups (and daily digests where a week has no rollup) back to %d days.', 'presshub-ai-editor' ), $bg ),
            __( 'The noon edition also gets the same day\'s morning digest, so it adds to the morning instead of repeating it.', 'presshub-ai-editor' ),
            __( 'The podcast also gets recent opening and closing lines, so the hosts do not open the same way every day.', 'presshub-ai-editor' ),
            __( 'When the memory is longer than its budget, PressHub drops the oldest background first, then the oldest recent days, then resolved stories before developing ones. It also shrinks memory when the articles alone come close to the model\'s limit.', 'presshub-ai-editor' ),
            __( 'The AI is told to continue stories rather than repeat them, refer to earlier coverage naturally, never invent connections, and never mention "our memory".', 'presshub-ai-editor' ),
            __( 'Excluded, failed and pending entries are never used, nor stories you switched off. Memory also starts the people and policy updates: they run after a digest is built.', 'presshub-ai-editor' ),
        ] );
        $html  .= self::h3( __( 'Editing memory', 'presshub-ai-editor' ) );
        $html  .= self::p( __( 'PressHub → Memory lists the digests and rollups. You can correct a story, switch a story off, add or delete one, exclude a whole day, regenerate a digest from its briefing, or reset an edited entry to what the AI produced. Edited entries keep your version when they are regenerated. Editing a day marks its weekly rollup stale; a stale rollup is still used until you rebuild it. The page also shows a preview of the exact memory text the next morning briefing would get.', 'presshub-ai-editor' ) );
        $html  .= self::h3( __( 'How it changes the output', 'presshub-ai-editor' ) );
        $html  .= self::effect(
            __( 'Without memory, the third day of a strike is written like the first: «Οι εκπαιδευτικοί ανακοίνωσαν απεργία…», with all the background again.', 'presshub-ai-editor' ),
            __( 'With memory, the briefing writes «Συνεχίζεται για τρίτη μέρα η απεργία των εκπαιδευτικών· σήμερα…» and spends its words on what is new. When the strike ends, the digest marks the story resolved and it fades out of later briefings.', 'presshub-ai-editor' )
        );
        $html  .= self::h3( __( 'User stories', 'presshub-ai-editor' ) );
        $html  .= self::story(
            __( 'A wrong fact in yesterday\'s briefing', 'presshub-ai-editor' ),
            __( 'As editor, I corrected a figure in yesterday\'s briefing and I do not want the wrong figure to come back today.', 'presshub-ai-editor' ),
            __( 'Updating the briefing post rebuilds its digest. To be sure, open the day in Memory, check the story and correct it by hand; your edit is kept even if the digest is regenerated.', 'presshub-ai-editor' )
        );
        $html  .= self::story(
            __( 'A day that should be forgotten', 'presshub-ai-editor' ),
            __( 'As editor, I want a briefing we unpublished to leave no trace in the next ones.', 'presshub-ai-editor' ),
            __( 'Exclude that day\'s digest in Memory (or delete the post, which removes its digest). Rebuild the weekly rollup it belongs to when it shows as stale.', 'presshub-ai-editor' )
        );
        $html  .= self::story(
            __( 'The podcast sounds the same every day', 'presshub-ai-editor' ),
            __( 'As podcast producer, I want different openings.', 'presshub-ai-editor' ),
            __( 'Keep memory on: the podcast gets the recent opening and closing lines and is told not to repeat them. If they still repeat, raise the podcast memory budget in Settings → Editorial knowledge.', 'presshub-ai-editor' )
        );
        return self::section( 'memory', $html );
    }

    private static function section_limitations(): string {
        $html  = self::h3( __( 'All four features', 'presshub-ai-editor' ) );
        $html .= self::ul( [
            __( 'They are instructions, not guarantees. The AI usually follows them, but it can still miss a rule or misread a fiche. The QA gate and a human read before publishing remain necessary.', 'presshub-ai-editor' ),
            __( 'They add text to every call that gets them, which costs tokens and time. The budgets keep this bounded; Usage & logs shows the cost.', 'presshub-ai-editor' ),
            __( 'They are only as good as what is in them. An outdated role, an expired theme without an end date or an unchecked "fact" is passed on as confidently as a correct one.', 'presshub-ai-editor' ),
            __( 'They do not reach the AI calls that only extract or classify (see the table), nor image and speech generation.', 'presshub-ai-editor' ),
        ] );
        $html .= self::h3( __( 'Editorial agenda', 'presshub-ai-editor' ) );
        $html .= self::ul( [
            __( 'It cannot be aimed at one task: what you write reaches every editorial task. Use the per-task budgets in Settings to turn it off for one task.', 'presshub-ai-editor' ),
            __( 'Up to 12 themes; titles up to 160 characters, notes up to 600, guidance up to 2,000. Small tasks get only the top themes.', 'presshub-ai-editor' ),
            __( 'It does not create news: a theme with no story in the material changes nothing in the text.', 'presshub-ai-editor' ),
        ] );
        $html .= self::h3( __( 'Policy fiches', 'presshub-ai-editor' ) );
        $html .= self::ul( [
            __( 'Matching is by words, not meaning. If the material says "the 13th pension" and none of your aliases does, the pension fiche is not used. A broad alias can also bring a fiche into stories where it does not belong.', 'presshub-ai-editor' ),
            __( 'One word under 6 letters never matches on its own, so acronyms need a longer phrase.', 'presshub-ai-editor' ),
            __( 'At most 3 fiches per task; with several busy files, the less mentioned ones are left out. The podcast never gets facts or open questions, and social copy and shorts get only the framing. A fiche too long for its task is sent with fewer parts (down to principles and framing), and in social copy it is left out.', 'presshub-ai-editor' ),
            sprintf(
                /* translators: 1: characters for social copy, 2: characters for shorts */
                __( 'The small tasks have little room once the fixed instructions are in: about %1$d characters for a fiche\'s title and framing in social copy and %2$d in the TikTok short plan, all fiches together. A fiche whose title and framing do not fit is not sent there at all; the Policies page warns you.', 'presshub-ai-editor' ),
                self::policy_room( 'social_copy_facebook' ),
                self::policy_room( 'social_tiktok_shorts' )
            ),
            __( 'The running record is filled by an AI reading PressHub\'s own briefings, so it can misread a claim or miss one. It only learns from briefings, not from co-authored articles or social posts.', 'presshub-ai-editor' ),
            __( 'They need editorial memory on, as well as their own switch.', 'presshub-ai-editor' ),
        ] );
        $html .= self::h3( __( 'People fiches', 'presshub-ai-editor' ) );
        $html .= self::ul( [
            __( 'Only the briefing and the podcast get them by default; the co-author, co-pilot, QA gate and social copy do not.', 'presshub-ai-editor' ),
            __( 'They are matched against the material only. A person referred to by title alone ("ο υπουργός") or by a nickname you have not added is not found.', 'presshub-ai-editor' ),
            __( 'The extraction can give a person the wrong role, split one person into two fiches or merge namesakes. Review new fiches now and then, merge duplicates and lock the important ones.', 'presshub-ai-editor' ),
            __( 'At most 12 people per task and 3 positions per person; 250 fiches in all. Single short names are not matched.', 'presshub-ai-editor' ),
            __( 'They need editorial memory on, as well as their own switch.', 'presshub-ai-editor' ),
        ] );
        $html .= self::h3( __( 'Editorial memory', 'presshub-ai-editor' ) );
        $html .= self::ul( [
            __( 'It remembers PressHub\'s own briefings only, not the rest of the site, other outlets or the social posts.', 'presshub-ai-editor' ),
            __( 'Digests and the people and policy updates run on WordPress scheduled events. On a quiet site they wait for the next visit unless a real server cron runs.', 'presshub-ai-editor' ),
            __( 'A digest is an AI summary and can be wrong or miss a story. A mistake there is carried into later briefings until you correct it in Memory.', 'presshub-ai-editor' ),
            __( 'Weekly rollups are built for completed weeks only, and a stale rollup is never rebuilt on its own.', 'presshub-ai-editor' ),
            /* translators: %d: days */
            sprintf( __( 'Entries older than %d days are deleted.', 'presshub-ai-editor' ), PressHub_AI_Settings_Storage::get_memory_retention_days() ),
        ] );
        return self::section( 'limits', $html );
    }

    private static function section_where(): string {
        $settings = PressHub_Settings_Sections::url( 'editorial-knowledge' );
        $rows     = [
            [ esc_html__( 'Write the agenda, accept suggestions', 'presshub-ai-editor' ), self::link( PressHub_AI_Fiches_Admin::agenda_url(), __( 'PressHub → Editorial agenda', 'presshub-ai-editor' ) ) ],
            [ esc_html__( 'Write, publish and lock policy fiches; review claims and suggestions', 'presshub-ai-editor' ), self::link( PressHub_AI_Policy_Fiches::page_url(), __( 'PressHub → Policies', 'presshub-ai-editor' ) ) ],
            [ esc_html__( 'Correct, lock, exclude and merge people fiches', 'presshub-ai-editor' ), self::link( PressHub_AI_Fiches_Admin::people_url(), __( 'PressHub → People', 'presshub-ai-editor' ) ) ],
            [ esc_html__( 'Review and correct memory; preview what the next briefing gets', 'presshub-ai-editor' ), self::link( PressHub_Admin_Menu::url( 'presshub-ai-memory' ), __( 'PressHub → Memory', 'presshub-ai-editor' ) ) ],
            [ esc_html__( 'Switches, memory windows and per-task budgets', 'presshub-ai-editor' ), self::link( $settings, __( 'Settings → Editorial knowledge', 'presshub-ai-editor' ) ) ],
            [ esc_html__( 'See exactly what one AI call received', 'presshub-ai-editor' ), self::link( PressHub_Admin_Menu::url( 'presshub-ai-generation-records' ), __( 'PressHub → Generation records', 'presshub-ai-editor' ) ) ],
        ];
        $html  = self::table( [ __( 'To…', 'presshub-ai-editor' ), __( 'Go to', 'presshub-ai-editor' ) ], $rows, true );
        $html .= self::p( __( 'Every briefing, podcast and other AI call has a generation record. Its "Editorial knowledge sent" section lists the agenda version, the people and the policy fiches (with their versions) that went into that call, and the system prompt shows them exactly as sent. When an output surprises you, start there: it tells you whether the AI ignored a fiche or never received it.', 'presshub-ai-editor' ) );
        return self::section( 'where', $html );
    }

    // ------------------------------------------------------------------
    // Sample data for the "what the AI receives" examples (never stored)
    // ------------------------------------------------------------------

    public static function sample_agenda(): array {
        return [
            'version'  => 4,
            'themes'   => [
                [ 'title' => 'Κυπριακό: συνομιλίες για επανέναρξη', 'priority' => 5, 'notes' => 'Ανέφερε πάντα σε ποιο στάδιο βρίσκονται οι επαφές. Οι δηλώσεις αποδίδονται σε όποιον τις κάνει.', 'expires' => '' ],
                [ 'title' => 'Στεγαστικό σχέδιο για νέα ζευγάρια', 'priority' => 3, 'notes' => 'Ποσά και κριτήρια μόνο από την επίσημη ανακοίνωση.', 'expires' => '' ],
            ],
            'guidance' => 'Τίτλοι περιγραφικοί, χωρίς θαυμαστικά. Ποσά πάντα με την πηγή τους.',
        ];
    }

    public static function sample_people_block(): string {
        $fiche = PressHub_AI_Stakeholders::normalize( [
            'name'      => 'Μαρία Ιωάννου',
            'name_en'   => 'Maria Ioannou',
            'role'      => 'Υπουργός Εργασίας',
            'party'     => '',
            'bio'       => 'Φανταστικό πρόσωπο για το παράδειγμα.',
            'positions' => [
                [ 'date' => '2026-10-02', 'topic' => 'συντάξεις', 'position' => 'Η αύξηση θα δοθεί από τον Ιανουάριο.' ],
                [ 'date' => '2026-11-10', 'topic' => 'συντάξεις', 'position' => 'Η αύξηση θα εξαρτηθεί από τον προϋπολογισμό.' ],
            ],
        ] );
        return PressHub_AI_Stakeholders::render( [ 'sample' => [ 'count' => 2, 'fiche' => $fiche ] ], PressHub_AI_Stakeholders::budget_for( 'briefing_curation' ) );
    }

    public static function sample_policy_block( string $action, bool $first_rule_only = false ): string {
        $data  = PressHub_AI_Policy_Fiches::example_fiche();
        if ( $first_rule_only ) {
            $data['framing'] = strtok( (string) $data['framing'], "\n" );
        }
        $fiche = PressHub_AI_Policy_Fiches::normalize( array_merge( PressHub_AI_Policy_Fiches::empty_fiche( 'Συνταξιοδοτική μεταρρύθμιση' ), $data, [
            'status'    => 'published',
            'facts'     => array_map( static function ( $x ) { return array_merge( [ 'url' => '' ], (array) $x ); }, (array) $data['facts'] ),
            'claims'    => array_map( static function ( $c ) { return array_merge( [ 'date' => '', 'url' => '', 'reason' => '' ], (array) $c ); }, (array) $data['claims'] ),
            'questions' => array_map( static function ( $q ) { return [ 'text' => (string) $q, 'status' => 'open' ]; }, (array) $data['questions'] ),
        ] ) );
        $budget = PressHub_AI_Policy_Fiches::budget_for( $action );
        $out    = PressHub_AI_Policy_Fiches::render( [ 'sample' => [ 'count' => 1, 'fiche' => $fiche ] ], $action, $budget );
        return (string) $out['text'];
    }

    /**
     * Characters left for one fiche's own text in a task, after the fixed
     * instructions that open and close the policy block. 0 when none.
     */
    public static function policy_room( string $action ): int {
        $budget = PressHub_AI_Policy_Fiches::budget_for( $action );
        if ( $budget <= 0 ) {
            return 0;
        }
        $probe = PressHub_AI_Policy_Fiches::normalize( array_merge( PressHub_AI_Policy_Fiches::empty_fiche( 'x' ), [ 'status' => 'published', 'framing' => 'x' ] ) );
        $block = PressHub_AI_Policy_Fiches::render( [ 'probe' => [ 'count' => 1, 'fiche' => $probe ] ], $action, 100000 )['text'];
        $own   = PressHub_AI_Policy_Fiches::render_fiche( $probe, [ 'framing' ] );
        return max( 0, $budget - ( self::strlen_u( (string) $block ) - self::strlen_u( $own ) ) );
    }

    private static function strlen_u( string $s ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
    }

    // ------------------------------------------------------------------
    // Markup helpers (all output escaped here)
    // ------------------------------------------------------------------

    private static function section( string $id, string $body ): string {
        $title = self::sections()[ $id ] ?? $id;
        return '<section class="presshub-help-section" id="presshub-help-' . esc_attr( $id ) . '"><h2>' . esc_html( $title ) . '</h2>' . $body
            . '<p class="presshub-help-top"><a href="#presshub-help-page">' . esc_html__( 'Back to top', 'presshub-ai-editor' ) . '</a></p></section>';
    }

    private static function p( string $text, string $class = '' ): string {
        return '<p' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . '>' . esc_html( $text ) . '</p>';
    }

    private static function h3( string $text ): string {
        return '<h3>' . esc_html( $text ) . '</h3>';
    }

    private static function ul( array $items ): string {
        return '<ul class="presshub-help-list"><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul>';
    }

    private static function ol( array $items ): string {
        return '<ol class="presshub-help-list"><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ol>';
    }

    private static function pre( string $text ): string {
        if ( '' === trim( $text ) ) {
            return self::p( __( 'With the current budgets this task would receive nothing.', 'presshub-ai-editor' ), 'description' );
        }
        return '<pre class="presshub-help-prompt" lang="el">' . esc_html( $text ) . '</pre>';
    }

    private static function link( string $url, string $label ): string {
        return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
    }

    private static function off_badge(): string {
        return '<span class="presshub-help-off">' . esc_html__( 'Off: not sent to the AI.', 'presshub-ai-editor' ) . '</span>';
    }

    /**
     * @param string[] $head  Header labels (plain text).
     * @param array[]  $rows  Cells, already escaped HTML.
     * @param bool     $first_is_header Render the first cell of each row as a row header.
     */
    private static function table( array $head, array $rows, bool $first_is_header = false ): string {
        $html = '<div class="presshub-help-table-wrap"><table class="widefat striped presshub-help-table"><thead><tr>';
        foreach ( $head as $h ) {
            $html .= '<th scope="col">' . esc_html( $h ) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $html .= '<tr>';
            foreach ( array_values( $row ) as $i => $cell ) {
                $html .= ( 0 === $i && $first_is_header ) ? '<th scope="row">' . $cell . '</th>' : '<td>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private static function effect( string $without, string $with ): string {
        return '<div class="presshub-help-effect">'
            . '<div class="presshub-help-effect-col"><h4>' . esc_html__( 'Without it', 'presshub-ai-editor' ) . '</h4><p>' . esc_html( $without ) . '</p></div>'
            . '<div class="presshub-help-effect-col"><h4>' . esc_html__( 'With it', 'presshub-ai-editor' ) . '</h4><p>' . esc_html( $with ) . '</p></div>'
            . '</div><p class="description">' . esc_html__( 'Illustrative wording: the AI\'s actual text varies from run to run.', 'presshub-ai-editor' ) . '</p>';
    }

    private static function story( string $title, string $want, string $how ): string {
        return '<div class="presshub-help-story"><h4>' . esc_html( $title ) . '</h4>'
            . '<p><strong>' . esc_html__( 'Story:', 'presshub-ai-editor' ) . '</strong> ' . esc_html( $want ) . '</p>'
            . '<p><strong>' . esc_html__( 'How the feature fits:', 'presshub-ai-editor' ) . '</strong> ' . esc_html( $how ) . '</p></div>';
    }

    private static function tips( array $items ): string {
        return self::h3( __( 'Tips', 'presshub-ai-editor' ) ) . self::ul( $items );
    }

    private static function chars( int $n ): string {
        if ( $n <= 0 ) {
            return '—';
        }
        $num = function_exists( 'number_format_i18n' ) ? number_format_i18n( $n ) : number_format( $n );
        /* translators: %s: number of characters */
        return sprintf( __( '%s chars', 'presshub-ai-editor' ), $num );
    }

    private static function parts_label( array $parts ): string {
        $labels = [
            'principles' => __( 'principles', 'presshub-ai-editor' ),
            'framing'    => __( 'framing', 'presshub-ai-editor' ),
            'facts'      => __( 'facts', 'presshub-ai-editor' ),
            'claims'     => __( 'claims', 'presshub-ai-editor' ),
            'questions'  => __( 'open questions', 'presshub-ai-editor' ),
        ];
        return implode( ', ', array_map( static function ( $p ) use ( $labels ) {
            return $labels[ $p ] ?? $p;
        }, $parts ) );
    }
}
