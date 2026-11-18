<?php
/**
 * PressHub_AI_Publish_Gate — the QA gate every post passes before it goes live.
 *
 * One AI review (the "Editorial QA" task in AI models) scores a post three
 * ways, each against its own threshold:
 *
 *   editorial  score 0-100 against "Minimum editorial score"       → fail holds
 *   legal      Low / Medium / High risk plus quoted issues           → fail alerts, holds or is off
 *   SEO        measured checks + the AI's SEO score (mean)           → fail alerts or holds
 *
 * The verdict is the strongest action of the failed checks: hold (keep the
 * post in Pending Review and email the editor), alert (publish and email) or
 * pass. A review whose AI call fails ends in "error", which callers treat
 * like hold, as the editorial gate always did.
 *
 * Every review is saved in PressHub_AI_QA_Review_Store (PressHub → QA
 * reviews) and the latest one in post meta; an unchanged post (same title,
 * content, excerpt, tags, categories and featured image) is never reviewed
 * twice. The editorial and legal answers come from the same call, and so
 * does the AI half of the SEO score when the prompt asks for it.
 *
 * Coverage (2.48.0): automated posts (Daily Briefing text and podcast,
 * Trending roundup) are inserted as pending with the status they should end
 * in kept in META_TARGET_STATUS (prepare_insert()), enriched with tags,
 * excerpt and image, and only then reviewed and moved to that status by
 * release(). Editors' posts and scheduled posts are checked by
 * PressHub_AI_Workflow. A held post can be released with publish_anyway(),
 * which is recorded on the review row and in the audit log.
 *
 * Contexts: post (articles), briefing, podcast (script and show notes,
 * measured SEO checks only) and trending (legal and measured SEO checks,
 * no editorial score).
 *
 * @package PressHub_AI_Editor
 * @since 2.47.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-settings-storage.php';
require_once __DIR__ . '/class-qa-reviewer.php';
require_once __DIR__ . '/class-qa-review-store.php';

class PressHub_AI_Publish_Gate {

    /** Post meta: the latest gate review (array, with its input hash). */
    const META_REVIEW = '_presshub_ai_gate_review';

    /** Post meta: the status an automated post goes to once the gate passes it. */
    const META_TARGET_STATUS = '_presshub_gate_target_status';

    /** Who scheduled the post: at go-live by cron, the editor opt-out looks at them. */
    const META_SCHEDULED_BY = '_presshub_ai_gate_scheduled_by';

    /** Post meta: input hash of the version an editor released with "Publish anyway". */
    const META_OVERRIDE = '_presshub_ai_gate_override';

    /** Review contexts. */
    const CONTEXTS = [ 'post', 'briefing', 'podcast', 'trending' ];

    /** Statuses release() may move a post to. */
    const RELEASE_STATUSES = [ 'publish', 'future', 'pending', 'draft', 'private' ];

    /** True while the gate itself changes a post's status, so the workflow gate does not review it again. */
    private static $releasing = false;

    /** Risk levels in rising order. */
    const RISKS = [ 'Low', 'Medium', 'High' ];

    /** Legal issue types the prompt may return. */
    const LEGAL_TYPES = [ 'defamation', 'presumption_of_innocence', 'privacy', 'minors_or_victims', 'hate_speech', 'copyright', 'reporting_restriction', 'other' ];

    /** SEO checks whose failure fails the SEO check outright. */
    const SEO_HARD_CHECKS = [ 'title', 'featured_image' ];

    /** Most legal issues kept per review. */
    const MAX_LEGAL_ISSUES = 10;

    /**
     * Review a saved post (author publish, Review button).
     *
     * @param WP_Post|object $post    The post as it is being published.
     * @param string         $trigger What asked for the review (author_publish, review_button, …).
     * @param array          $opts    context ('post'|'briefing'), force (bool: ignore the cache),
     *                                content (string: editor text instead of post_content),
     *                                seo_input (array: editor state instead of the saved post).
     * @return array Gate result (see run()).
     */
    public static function review_post( $post, string $trigger, array $opts = [] ): array {
        $post_id = is_object( $post ) && ! empty( $post->ID ) ? (int) $post->ID : 0;
        $context = self::context( (string) ( $opts['context'] ?? 'post' ) );
        $input   = self::seo_input_for( $post, $opts );
        if ( ! isset( $opts['content'] ) && 'podcast' === $context ) {
            $opts['content'] = self::podcast_text( $post );
        }
        $content = isset( $opts['content'] ) ? (string) $opts['content'] : (string) ( $input['content'] ?? '' );
        $hash    = self::input_hash( $input, $context, isset( $opts['content'] ) ? $content : '' );

        if ( empty( $opts['force'] ) && $post_id > 0 ) {
            $cached = get_post_meta( $post_id, self::META_REVIEW, true );
            if ( is_array( $cached ) && ( $cached['content_hash'] ?? '' ) === $hash && 'error' !== ( $cached['verdict'] ?? '' ) ) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        $gate = self::run( $content, $context, $post_id, $input );
        if ( $post_id > 0 && isset( $gate['seo_input_after_fix'] ) ) {
            // The SEO fix filled the excerpt or tags: cache under the fixed version.
            $hash = self::input_hash( (array) $gate['seo_input_after_fix'], $context, isset( $opts['content'] ) ? $content : '' );
        }
        unset( $gate['seo_input_after_fix'] );
        return self::save( $gate, $post_id, $trigger, $hash );
    }

    /**
     * Review text that is not a post yet (a Daily Briefing story before it is
     * inserted). The SEO check is skipped: tags, excerpt and image do not
     * exist yet.
     */
    public static function review_content( string $content, string $context, string $trigger ): array {
        $context = self::context( $context );
        $gate    = self::run( $content, $context, 0, null );
        $gate['trigger']      = $trigger;
        $gate['content_hash'] = md5( trim( $content ) );
        return $gate;
    }

    /**
     * Attach a review made before the post existed (review_content()) to the
     * post: save it in the history and in post meta.
     */
    public static function attach( array $gate, int $post_id ): array {
        if ( $post_id <= 0 ) {
            return $gate;
        }
        return self::save( $gate, $post_id, (string) ( $gate['trigger'] ?? '' ), (string) ( $gate['content_hash'] ?? '' ) );
    }

    /**
     * Email the editor when the verdict is not a pass (once per content version).
     */
    public static function notify( int $post_id, array $gate ): bool {
        if ( $post_id <= 0 || 'pass' === ( $gate['verdict'] ?? 'pass' ) ) {
            return false;
        }
        return PressHub_AI_QA_Reviewer::notify_editor_failure( $post_id, $gate, (string) ( $gate['context'] ?? 'post' ) );
    }

    /** Whether a verdict keeps the post out of publication. */
    public static function holds( array $gate ): bool {
        return in_array( (string) ( $gate['verdict'] ?? 'error' ), [ 'hold', 'error' ], true );
    }

    /** The hash a review of this post would be cached under. */
    public static function input_hash_for_post( $post, string $context = 'post' ): string {
        $context = self::context( $context );
        return self::input_hash( self::seo_input_for( $post, [] ), $context, 'podcast' === $context ? self::podcast_text( $post ) : '' );
    }

    /** The review context of a post: briefing, podcast or trending for automated posts, else post. */
    public static function context_for_post( $post ): string {
        $post_id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : 0;
        if ( $post_id <= 0 ) {
            return 'post';
        }
        $type = (string) get_post_meta( $post_id, '_presshub_briefing_type', true );
        if ( 'podcast' === $type ) {
            return 'podcast';
        }
        if ( '' !== $type ) {
            return 'briefing';
        }
        return '' !== (string) get_post_meta( $post_id, '_presshub_social_trending_key', true ) ? 'trending' : 'post';
    }

    /** What a podcast review reads: the show notes and the spoken script. */
    public static function podcast_text( $post ): string {
        $post_id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : 0;
        $script  = $post_id > 0 ? (string) get_post_meta( $post_id, '_presshub_podcast_script', true ) : '';
        $notes   = is_object( $post ) ? (string) ( $post->post_content ?? '' ) : '';
        return '' === trim( $script ) ? $notes : $notes . "\n\n" . __( 'Podcast script:', 'presshub-ai-editor' ) . "\n" . $script;
    }

    /**
     * Take a live post off line while new content is written and reviewed
     * (an updated Trending roundup): it goes to pending with its status kept
     * for release().
     */
    public static function hold_for_review( int $post_id ): void {
        $post = $post_id > 0 ? get_post( $post_id ) : null;
        if ( ! $post || ! in_array( (string) ( $post->post_status ?? '' ), [ 'publish', 'future' ], true ) ) {
            return;
        }
        update_post_meta( $post_id, self::META_TARGET_STATUS, (string) $post->post_status );
        self::move( $post_id, 'pending' );
    }

    // ------------------------------------------------------------------
    // Coverage: which posts the gate checks, and releasing held posts
    // ------------------------------------------------------------------

    /** Whether the gate checks posts of this context (the QA switch and its include setting). */
    public static function applies( string $context ): bool {
        if ( ! PressHub_AI_Settings_Storage::get_qa_enabled() ) {
            return false;
        }
        switch ( self::context( $context ) ) {
            case 'briefing':
                return PressHub_AI_Settings_Storage::get_qa_include_briefings();
            case 'podcast':
                return PressHub_AI_Settings_Storage::get_qa_include_podcasts();
            case 'trending':
                return PressHub_AI_Settings_Storage::get_qa_include_trending();
            default:
                return true;
        }
    }

    /** Whether the gate is changing a post's status right now. */
    public static function is_releasing(): bool {
        return self::$releasing;
    }

    /**
     * Before inserting an automated post: when the gate checks this context
     * and the post would go live (publish or future), insert it as pending
     * and remember the status for release().
     */
    public static function prepare_insert( array $postarr, string $context ): array {
        $status = (string) ( $postarr['post_status'] ?? 'draft' );
        if ( ! self::applies( $context ) || ! in_array( $status, [ 'publish', 'future' ], true ) ) {
            return $postarr;
        }
        $postarr['post_status']                             = 'pending';
        $postarr['meta_input']                              = (array) ( $postarr['meta_input'] ?? [] );
        $postarr['meta_input'][ self::META_TARGET_STATUS ] = $status;
        return $postarr;
    }

    /**
     * Review an automated post once its tags, excerpt and image are in place,
     * then move it to the status prepare_insert() kept, unless the verdict
     * holds it. A post already live (an updated Trending roundup) that is
     * held goes back to Pending Review. Alerts go to the editor either way.
     *
     * @param array $opts Passed to review_post() (content, force).
     * @return array|null The gate result, or null when the gate does not check this context.
     */
    public static function release( int $post_id, string $context, string $trigger, array $opts = [] ): ?array {
        $post = $post_id > 0 ? get_post( $post_id ) : null;
        if ( ! $post ) {
            return null;
        }
        $target = (string) get_post_meta( $post_id, self::META_TARGET_STATUS, true );

        if ( ! self::applies( $context ) ) {
            if ( '' !== $target ) {
                self::move( $post_id, $target );
                delete_post_meta( $post_id, self::META_TARGET_STATUS );
            }
            return null;
        }

        $gate = self::review_post( $post, $trigger, array_merge( $opts, [ 'context' => $context ] ) );
        if ( self::holds( $gate ) ) {
            if ( '' === $target && in_array( (string) $post->post_status, [ 'publish', 'future' ], true ) ) {
                update_post_meta( $post_id, self::META_TARGET_STATUS, (string) $post->post_status );
                self::move( $post_id, 'pending' );
            }
            self::log( 'warning', sprintf( 'QA gate held %s post #%d in Pending Review (%s).', $context, $post_id, $gate['verdict'] ), $post_id );
        } elseif ( '' !== $target ) {
            self::move( $post_id, $target );
            delete_post_meta( $post_id, self::META_TARGET_STATUS );
        }
        self::notify( $post_id, $gate );
        return $gate;
    }

    /**
     * "Publish anyway": an editor releases a held post. The override is
     * written on the latest review row and in the audit log, and the post goes
     * to the status it was held from (publish when unknown).
     *
     * @return true|WP_Error
     */
    public static function publish_anyway( int $post_id, string $reason ) {
        $post = $post_id > 0 ? get_post( $post_id ) : null;
        if ( ! $post || 'post' !== ( $post->post_type ?? '' ) ) {
            return new WP_Error( 'presshub_qa_no_post', __( 'That post does not exist.', 'presshub-ai-editor' ) );
        }
        $reason = trim( sanitize_textarea_field( $reason ) );
        if ( '' === $reason ) {
            return new WP_Error( 'presshub_qa_no_reason', __( 'Say why the post is published despite the QA gate.', 'presshub-ai-editor' ) );
        }
        $review  = get_post_meta( $post_id, self::META_REVIEW, true );
        $review  = is_array( $review ) ? $review : [];
        $context = self::context( (string) ( $review['context'] ?? 'post' ) );
        // Only a post the gate is holding, in the version it held.
        if ( 'pending' !== ( $post->post_status ?? '' ) || ! $review || ! self::holds( $review ) ) {
            return new WP_Error( 'presshub_qa_not_held', __( 'This post is not held by the QA gate.', 'presshub-ai-editor' ) );
        }
        if ( (string) ( $review['content_hash'] ?? '' ) !== self::input_hash_for_post( $post, $context ) ) {
            return new WP_Error( 'presshub_qa_changed', __( 'The post changed after the QA gate held it. Publish it again so the gate reviews the new version.', 'presshub-ai-editor' ) );
        }
        $target  = (string) get_post_meta( $post_id, self::META_TARGET_STATUS, true );
        if ( ! in_array( $target, [ 'publish', 'future', 'private' ], true ) ) {
            $target = ( isset( $post->post_date_gmt ) && strtotime( (string) $post->post_date_gmt . ' UTC' ) > time() ) ? 'future' : 'publish';
        }

        $failed = [];
        foreach ( (array) ( $review['checks'] ?? [] ) as $key => $check ) {
            if ( in_array( $check['result'] ?? '', [ 'fail', 'error' ], true ) ) {
                $failed[] = $key;
            }
        }
        if ( ! empty( $review['review_id'] ) ) {
            PressHub_AI_QA_Review_Store::record_override( (int) $review['review_id'], $reason );
        }
        update_post_meta( $post_id, self::META_OVERRIDE, self::input_hash_for_post( $post, $context ) );
        if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
            PressHub_AI_Audit_Logger::log( 'qa_gate_override', 'post', (string) $post_id, [
                'reason'    => $reason,
                'verdict'   => (string) ( $review['verdict'] ?? '' ),
                'failed'    => $failed,
                'review_id' => (int) ( $review['review_id'] ?? 0 ),
                'status'    => $target,
            ] );
        }
        self::move( $post_id, $target );
        delete_post_meta( $post_id, self::META_TARGET_STATUS );
        self::log( 'info', sprintf( 'QA gate: post #%d published anyway (%s).', $post_id, $reason ), $post_id );
        return true;
    }

    /** Whether an editor released this exact version with "Publish anyway". */
    public static function is_overridden( $post, string $context = 'post' ): bool {
        $post_id = is_object( $post ) ? (int) ( $post->ID ?? 0 ) : 0;
        if ( $post_id <= 0 ) {
            return false;
        }
        $saved = (string) get_post_meta( $post_id, self::META_OVERRIDE, true );
        return '' !== $saved && $saved === self::input_hash_for_post( $post, $context );
    }

    /** Change a post's status without the workflow gate reviewing it again. */
    private static function move( int $post_id, string $status ): void {
        if ( ! in_array( $status, self::RELEASE_STATUSES, true ) ) {
            return;
        }
        self::$releasing = true;
        try {
            wp_update_post( [ 'ID' => $post_id, 'post_status' => $status ] );
        } finally {
            self::$releasing = false;
        }
    }

    private static function log( string $level, string $message, int $post_id ): void {
        if ( class_exists( 'PressHub_AI_Logger' ) && method_exists( 'PressHub_AI_Logger', $level ) ) {
            PressHub_AI_Logger::$level( $message, [ 'post_id' => $post_id ] );
        }
    }

    private static function context( string $context ): string {
        return in_array( $context, self::CONTEXTS, true ) ? $context : 'post';
    }

    // ------------------------------------------------------------------
    // The review
    // ------------------------------------------------------------------

    /**
     * Run the three checks.
     *
     * @param array|null $seo_input See PressHub_AI_SEO::run_checks(); null skips the SEO check.
     * @return array verdict, context, checks{editorial,legal,seo}, error, reviewed_at,
     *               plus score / feedback / libel_risk / legal_concerns for the
     *               scorecard meta and older readers.
     */
    public static function run( string $content, string $context, int $post_id, ?array $seo_input ): array {
        $seo_enabled = null !== $seo_input && PressHub_AI_Settings_Storage::get_seo_review_enabled() && class_exists( 'PressHub_AI_SEO' );
        $seo_checks  = $seo_enabled ? PressHub_AI_SEO::run_checks( $seo_input ) : null;

        $context  = self::context( $context );
        // Articles use the article prompt (editorial, legal and SEO); the
        // automated news formats use the briefing prompt (editorial and legal).
        $template = 'post' === $context
            ? PressHub_AI_Settings_Storage::get_qa_article_prompt()
            : PressHub_AI_Settings_Storage::get_qa_briefing_prompt();
        $template = strtr( $template, [
            '{title}'         => trim( (string) ( $seo_input['title'] ?? '' ) ),
            '{seo_facts}'     => $seo_checks ? PressHub_AI_SEO::facts_text( $seo_input, $seo_checks ) : '(not measured)',
            '{existing_tags}' => $seo_enabled ? PressHub_AI_SEO::existing_tags_text() : '(none)',
        ] );

        $eval   = PressHub_AI_QA_Reviewer::evaluate_content( $content, 'post' === $context ? 'post' : 'briefing', $post_id, $template );
        $raw    = is_array( $eval['raw'] ?? null ) ? $eval['raw'] : [];
        $ai_ran = null === $eval['error'];

        $seo_fixed = false;
        if ( ! $seo_enabled ) {
            $seo = [ 'result' => 'skip' ];
        } elseif ( ! $ai_ran ) {
            // The review did not run: the SEO result would only be half a score.
            $seo = [ 'result' => 'error', 'action' => PressHub_AI_Settings_Storage::get_qa_seo_action() ];
        } else {
            // A second SEO call only for articles and briefings; podcasts and the roundup get the measured checks.
            $seo = self::seo_check( $seo_checks, $raw, $seo_input, in_array( $context, [ 'post', 'briefing' ], true ) );
            if ( 'fail' === $seo['result'] && $post_id > 0 ) {
                $fixed = self::fix_seo( $post_id, $seo, $seo_input );
                if ( null !== $fixed ) {
                    $seo_input = $fixed['input'];
                    unset( $fixed['input'] );
                    $seo       = $fixed;
                    $seo_fixed = true;
                }
            }
        }

        $checks = [
            'editorial' => 'trending' === $context ? [ 'result' => 'skip' ] : self::editorial_check( $eval ),
            'legal'     => self::legal_check( $eval, $raw ),
            'seo'       => $seo,
        ];

        $verdict = self::verdict( $checks );

        return [
            'verdict'        => $verdict,
            'context'        => $context,
            'checks'         => $checks,
            'error'          => $eval['error'],
            'reviewed_at'    => gmdate( 'c' ),
            'content_md5'    => md5( trim( $content ) ),
            'score'          => (int) $eval['score'],
            'feedback'       => (string) $eval['feedback'],
            'libel_risk'     => (string) ( $checks['legal']['risk'] ?? $eval['libel_risk'] ),
            'legal_concerns' => (string) $eval['legal_concerns'],
            'passed'         => ! in_array( $verdict, [ 'hold', 'error' ], true ),
            'seo_fixed'      => $seo_fixed,
        ] + ( $seo_fixed ? [ 'seo_input_after_fix' => $seo_input ] : [] );
    }

    /**
     * The verdict from the check results and the configured actions.
     */
    public static function verdict( array $checks ): string {
        foreach ( [ 'editorial', 'legal', 'seo' ] as $key ) {
            if ( 'error' === ( $checks[ $key ]['result'] ?? '' ) ) {
                return 'error';
            }
        }
        $actions = [];
        if ( 'fail' === ( $checks['editorial']['result'] ?? '' ) ) {
            $actions[] = 'hold';
        }
        foreach ( [ 'legal', 'seo' ] as $key ) {
            if ( 'fail' === ( $checks[ $key ]['result'] ?? '' ) ) {
                $actions[] = 'hold' === ( $checks[ $key ]['action'] ?? 'alert' ) ? 'hold' : 'alert';
            }
        }
        if ( in_array( 'hold', $actions, true ) ) {
            return 'hold';
        }
        return $actions ? 'alert' : 'pass';
    }

    private static function editorial_check( array $eval ): array {
        $threshold = PressHub_AI_Settings_Storage::get_qa_min_score();
        if ( null !== $eval['error'] ) {
            return [
                'result'    => 'error',
                'score'     => null,
                'threshold' => $threshold,
                'feedback'  => (string) $eval['feedback'],
                'error'     => (string) $eval['error'],
            ];
        }
        $score = (int) $eval['score'];
        return [
            'result'    => $score >= $threshold ? 'pass' : 'fail',
            'score'     => $score,
            'threshold' => $threshold,
            'feedback'  => (string) $eval['feedback'],
        ];
    }

    private static function legal_check( array $eval, array $raw ): array {
        $action = PressHub_AI_Settings_Storage::get_qa_legal_action();
        if ( 'off' === $action ) {
            return [ 'result' => 'skip', 'action' => 'off' ];
        }
        $fail_risk = PressHub_AI_Settings_Storage::get_qa_legal_fail_risk();
        if ( null !== $eval['error'] ) {
            return [ 'result' => 'error', 'action' => $action, 'fail_risk' => $fail_risk ];
        }

        $issues = self::legal_issues( (array) ( $raw['legal_issues'] ?? [] ) );
        $risk   = self::risk( (string) ( $eval['libel_risk'] ?? 'Low' ) );
        // An issue rated above the overall risk raises it.
        foreach ( $issues as $issue ) {
            if ( self::risk_rank( $issue['severity'] ) > self::risk_rank( $risk ) ) {
                $risk = $issue['severity'];
            }
        }
        $fails = self::risk_rank( $risk ) >= self::risk_rank( 'medium' === $fail_risk ? 'Medium' : 'High' );

        return [
            'result'    => $fails ? 'fail' : 'pass',
            'action'    => $action,
            'risk'      => $risk,
            'fail_risk' => $fail_risk,
            'concerns'  => (string) ( $eval['legal_concerns'] ?? '' ),
            'issues'    => $issues,
        ];
    }

    /**
     * @param array $checks    PressHub_AI_SEO::run_checks() output.
     * @param array $raw       The scorecard JSON; its "seo" object is the AI half.
     * @param bool  $ask_seo   Ask the SEO reviewer separately when the prompt has no SEO part.
     */
    private static function seo_check( array $checks, array $raw, array $input, bool $ask_seo ): array {
        $review = PressHub_AI_SEO::checks_result( $checks );
        if ( isset( $raw['seo'] ) && is_array( $raw['seo'] ) ) {
            $review = PressHub_AI_SEO::merge_ai_assessment( $review, $raw['seo'] );
        } elseif ( $ask_seo ) {
            // A custom QA prompt without the SEO part: ask the SEO reviewer on its own.
            $review = PressHub_AI_SEO::review( $input );
        }

        return self::seo_result( $review );
    }

    /** The SEO check from an SEO review: score against the minimum, plus the hard checks. */
    private static function seo_result( array $review ): array {
        $hard_fail = [];
        foreach ( (array) $review['checks'] as $c ) {
            if ( in_array( $c['key'] ?? '', self::SEO_HARD_CHECKS, true ) && 'fail' === ( $c['status'] ?? '' ) ) {
                $hard_fail[] = (string) $c['key'];
            }
        }
        $threshold = PressHub_AI_Settings_Storage::get_qa_seo_min_score();
        $score     = (int) $review['seo_score'];

        return [
            'result'    => ( $score >= $threshold && ! $hard_fail ) ? 'pass' : 'fail',
            'action'    => PressHub_AI_Settings_Storage::get_qa_seo_action(),
            'score'     => $score,
            'threshold' => $threshold,
            'hard_fail' => $hard_fail,
            'review'    => $review,
        ];
    }

    /**
     * The automatic SEO fix: when the excerpt or the tags are what fails,
     * apply the AI's suggested meta description and tags (from the same
     * review, no extra call) and measure again. Only empty fields are filled,
     * and only when "Automatic Tags & SEO" is on.
     *
     * @return array|null The SEO check after the fix, or null when nothing was fixed.
     */
    private static function fix_seo( int $post_id, array $seo, array $input ): ?array {
        if ( ! PressHub_AI_Settings_Storage::get_seo_auto_enabled() ) {
            return null;
        }
        $review  = (array) ( $seo['review'] ?? [] );
        $failing = [];
        foreach ( (array) ( $review['checks'] ?? [] ) as $c ) {
            if ( 'fail' === ( $c['status'] ?? '' ) ) {
                $failing[] = (string) ( $c['key'] ?? '' );
            }
        }
        $description = (string) ( $review['suggested_meta_description'] ?? '' );
        // The same cleanup apply_post_seo() does, so the cached version matches the saved tags.
        $tags        = PressHub_AI_SEO::normalize_tags( (array) ( $review['suggested_tags'] ?? [] ), PressHub_AI_SEO::max_tags() );
        $set_excerpt = in_array( 'excerpt', $failing, true ) && '' === trim( (string) ( $input['excerpt'] ?? '' ) ) && '' !== $description;
        // Tags sent with a block editor save are written after this review, so leave them to the editor.
        $set_tags    = in_array( 'tags', $failing, true ) && empty( $input['tags'] ) && ! empty( $tags ) && empty( $input['tags_from_request'] );
        if ( ! $set_excerpt && ! $set_tags ) {
            return null;
        }

        $before = get_post_meta( $post_id, PressHub_AI_SEO::META_SEO, true );
        $before = is_array( $before ) ? $before : [];
        PressHub_AI_SEO::apply_post_seo( $post_id, [
            'tags'             => $set_tags ? $tags : [],
            'meta_description' => $set_excerpt ? $description : '',
            'focus_keyphrase'  => '' !== (string) ( $before['focus_keyphrase'] ?? '' ) ? '' : (string) ( $review['focus_keyphrase'] ?? '' ),
        ], $set_excerpt, $set_tags );
        if ( $before ) {
            // apply_post_seo() rewrites the whole SEO meta: keep what this fix did not set.
            $after = get_post_meta( $post_id, PressHub_AI_SEO::META_SEO, true );
            $after = is_array( $after ) ? $after : [];
            foreach ( [ 'tags', 'meta_description', 'focus_keyphrase' ] as $field ) {
                if ( empty( $after[ $field ] ) && ! empty( $before[ $field ] ) ) {
                    $after[ $field ] = $before[ $field ];
                }
            }
            update_post_meta( $post_id, PressHub_AI_SEO::META_SEO, $after );
        }
        if ( $set_excerpt ) {
            $input['excerpt'] = $description;
        }
        if ( $set_tags ) {
            $input['tags'] = array_values( array_map( 'strval', $tags ) );
        }

        $checks             = PressHub_AI_SEO::run_checks( $input );
        $review['checks']   = $checks['checks'];
        $review['checks_score'] = $checks['score'];
        $review['seo_score']    = null === ( $review['ai_score'] ?? null ) ? $checks['score'] : (int) round( ( $checks['score'] + (int) $review['ai_score'] ) / 2 );
        $result             = self::seo_result( $review );
        $result['fixed']    = array_values( array_filter( [ $set_excerpt ? 'excerpt' : '', $set_tags ? 'tags' : '' ] ) );
        $result['input']    = $input;
        self::log( 'info', sprintf( 'QA gate: filled the missing %s of post #%d from the SEO review.', implode( ' and ', $result['fixed'] ), $post_id ), $post_id );
        return $result;
    }

    private static function legal_issues( array $raw_issues ): array {
        $out = [];
        foreach ( $raw_issues as $issue ) {
            if ( ! is_array( $issue ) ) {
                continue;
            }
            $quote = self::line( (string) ( $issue['quote'] ?? '' ), 500 );
            $fix   = self::line( (string) ( $issue['fix'] ?? '' ), 500 );
            if ( '' === $quote && '' === $fix ) {
                continue;
            }
            $type  = sanitize_key( (string) ( $issue['type'] ?? 'other' ) );
            $out[] = [
                'type'     => in_array( $type, self::LEGAL_TYPES, true ) ? $type : 'other',
                'quote'    => $quote,
                'severity' => self::risk( (string) ( $issue['severity'] ?? 'Low' ) ),
                'fix'      => $fix,
            ];
            if ( count( $out ) >= self::MAX_LEGAL_ISSUES ) {
                break;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Saving
    // ------------------------------------------------------------------

    private static function save( array $gate, int $post_id, string $trigger, string $hash ): array {
        $gate['trigger']      = $trigger;
        $gate['content_hash'] = $hash;
        $gate['post_id']      = $post_id;

        $review_id = PressHub_AI_QA_Review_Store::record( $gate );
        if ( null !== $review_id ) {
            $gate['review_id'] = $review_id;
        }

        if ( $post_id > 0 ) {
            update_post_meta( $post_id, self::META_REVIEW, $gate );
        }
        // A review that could not run keeps the last good scorecard and SEO
        // review: it is still recorded in the history above.
        if ( $post_id > 0 && 'error' !== $gate['verdict'] ) {
            // The scorecard the editor metabox and the Social / Briefing screens read.
            update_post_meta( $post_id, PressHub_AI_QA_Reviewer::META_SCORECARD, [
                'score'          => $gate['score'],
                'feedback'       => $gate['feedback'],
                'libel_risk'     => $gate['libel_risk'],
                'legal_concerns' => $gate['legal_concerns'],
            ] );
            update_post_meta( $post_id, PressHub_AI_QA_Reviewer::META_CONTENT_HASH, (string) ( $gate['content_md5'] ?? '' ) );
            if ( 'skip' !== ( $gate['checks']['seo']['result'] ?? 'skip' ) && class_exists( 'PressHub_AI_SEO' ) ) {
                update_post_meta( $post_id, PressHub_AI_SEO::META_SEO_REVIEW, $gate['checks']['seo']['review'] );
            }
        }

        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( sprintf( 'QA gate review (%s) for post #%d: %s', $trigger, $post_id, strtoupper( (string) $gate['verdict'] ) ), [
                'post_id'   => $post_id,
                'trigger'   => $trigger,
                'verdict'   => $gate['verdict'],
                'editorial' => $gate['checks']['editorial']['result'] ?? '',
                'legal'     => $gate['checks']['legal']['result'] ?? '',
                'seo'       => $gate['checks']['seo']['result'] ?? '',
                'review_id' => $review_id,
            ] );
        }
        return $gate;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function seo_input_for( $post, array $opts ): array {
        if ( isset( $opts['seo_input'] ) && is_array( $opts['seo_input'] ) ) {
            $input = $opts['seo_input'];
        } else {
            $post_id = is_object( $post ) && ! empty( $post->ID ) ? (int) $post->ID : 0;
            $input   = class_exists( 'PressHub_AI_SEO' ) ? PressHub_AI_SEO::input_for_post( $post_id ) : [ 'post_id' => $post_id ];
            // The post being published may be newer than the stored copy.
            if ( is_object( $post ) ) {
                foreach ( [ 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt' ] as $key => $field ) {
                    if ( isset( $post->$field ) ) {
                        $input[ $key ] = (string) $post->$field;
                    }
                }
            }
        }
        if ( isset( $opts['content'] ) ) {
            $input['content'] = (string) $opts['content'];
        }
        return $input;
    }

    private static function input_hash( array $input, string $context, string $reviewed_text = '' ): string {
        $tags = array_map( 'strval', (array) ( $input['tags'] ?? [] ) );
        $cats = array_map( 'strval', (array) ( $input['categories'] ?? [] ) );
        sort( $tags );
        sort( $cats );
        return md5( (string) json_encode( [
            $context,
            trim( (string) ( $input['title'] ?? '' ) ),
            trim( (string) ( $input['content'] ?? '' ) ),
            trim( (string) ( $input['excerpt'] ?? '' ) ),
            $tags,
            $cats,
            (int) ( $input['featured_image_id'] ?? 0 ),
            self::settings_fingerprint(),
            '' === $reviewed_text ? '' : md5( trim( $reviewed_text ) ),
        ] ) );
    }

    /**
     * The settings a verdict depends on, so a saved review is not reused
     * after an editor tightens or loosens the gate.
     */
    private static function settings_fingerprint(): array {
        if ( ! class_exists( 'PressHub_AI_Settings_Storage' ) ) {
            return [];
        }
        return [
            PressHub_AI_Settings_Storage::get_qa_min_score(),
            PressHub_AI_Settings_Storage::get_qa_legal_action(),
            PressHub_AI_Settings_Storage::get_qa_legal_fail_risk(),
            PressHub_AI_Settings_Storage::get_seo_review_enabled() ? 1 : 0,
            PressHub_AI_Settings_Storage::get_qa_seo_action(),
            PressHub_AI_Settings_Storage::get_qa_seo_min_score(),
        ];
    }

    private static function risk( string $value ): string {
        $value = ucfirst( strtolower( trim( $value ) ) );
        return in_array( $value, self::RISKS, true ) ? $value : 'Low';
    }

    private static function risk_rank( string $risk ): int {
        $i = array_search( self::risk( $risk ), self::RISKS, true );
        return false === $i ? 0 : (int) $i;
    }

    private static function line( string $text, int $max ): string {
        $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
        return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '…' : $text;
    }
}
