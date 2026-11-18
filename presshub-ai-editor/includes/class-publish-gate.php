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
        $context = 'briefing' === ( $opts['context'] ?? 'post' ) ? 'briefing' : 'post';
        $input   = self::seo_input_for( $post, $opts );
        $content = isset( $opts['content'] ) ? (string) $opts['content'] : (string) ( $input['content'] ?? '' );
        $hash    = self::input_hash( $input, $context );

        if ( empty( $opts['force'] ) && $post_id > 0 ) {
            $cached = get_post_meta( $post_id, self::META_REVIEW, true );
            if ( is_array( $cached ) && ( $cached['content_hash'] ?? '' ) === $hash && 'error' !== ( $cached['verdict'] ?? '' ) ) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        $gate = self::run( $content, $context, $post_id, $input );
        return self::save( $gate, $post_id, $trigger, $hash );
    }

    /**
     * Review text that is not a post yet (a Daily Briefing story before it is
     * inserted). The SEO check is skipped: tags, excerpt and image do not
     * exist yet.
     */
    public static function review_content( string $content, string $context, string $trigger ): array {
        $context = 'briefing' === $context ? 'briefing' : 'post';
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
        return self::input_hash( self::seo_input_for( $post, [] ), 'briefing' === $context ? 'briefing' : 'post' );
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

        $template = 'briefing' === $context
            ? PressHub_AI_Settings_Storage::get_qa_briefing_prompt()
            : PressHub_AI_Settings_Storage::get_qa_article_prompt();
        $template = strtr( $template, [
            '{title}'         => trim( (string) ( $seo_input['title'] ?? '' ) ),
            '{seo_facts}'     => $seo_checks ? PressHub_AI_SEO::facts_text( $seo_input, $seo_checks ) : '(not measured)',
            '{existing_tags}' => $seo_enabled ? PressHub_AI_SEO::existing_tags_text() : '(none)',
        ] );

        $eval = PressHub_AI_QA_Reviewer::evaluate_content( $content, $context, $post_id, $template );
        $raw  = is_array( $eval['raw'] ?? null ) ? $eval['raw'] : [];

        $checks = [
            'editorial' => self::editorial_check( $eval ),
            'legal'     => self::legal_check( $eval, $raw ),
            'seo'       => $seo_enabled
                ? self::seo_check( $seo_checks, $raw, $seo_input, null === $eval['error'] )
                : [ 'result' => 'skip' ],
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
        ];
    }

    /**
     * The verdict from the check results and the configured actions.
     */
    public static function verdict( array $checks ): string {
        if ( 'error' === ( $checks['editorial']['result'] ?? '' ) ) {
            return 'error';
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
     * @param bool  $ai_ran    Whether the scorecard call succeeded.
     */
    private static function seo_check( array $checks, array $raw, array $input, bool $ai_ran ): array {
        $review = PressHub_AI_SEO::checks_result( $checks );
        if ( isset( $raw['seo'] ) && is_array( $raw['seo'] ) ) {
            $review = PressHub_AI_SEO::merge_ai_assessment( $review, $raw['seo'] );
        } elseif ( $ai_ran ) {
            // A custom QA prompt without the SEO part: ask the SEO reviewer on its own.
            $review = PressHub_AI_SEO::review( $input );
        }

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

    private static function input_hash( array $input, string $context ): string {
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
