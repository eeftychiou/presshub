<?php
/**
 * PressHub_AI_Policy_Fiches - one fiche per policy file (pension reform,
 * Cyprus problem, housing...), so the LLM calls follow the newsroom's line on
 * that file and check the claims made about it.
 *
 *  - Core (editor-owned, never rewritten automatically): title, aliases used
 *    for matching, principles, framing rules, confirmed facts. Every core save
 *    is a version (the last HISTORY_MAX are kept).
 *  - Running record (added to automatically, one dated item at a time):
 *    timeline, claims register (unchecked / contested / confirmed / false),
 *    actors and open questions. Only the editor marks a claim confirmed or
 *    false; the system may mark it contested, with a reason.
 *  - Suggestions: what the system would change in the core waits for the
 *    editor (accept / dismiss).
 *  - Build: first drafts from the agenda and the editorial memory (a button,
 *    run as a cron job); after each published brief one structured AI call
 *    adds to the matched fiches and counts new topics, and a topic seen in
 *    NEW_TOPIC_EDITIONS editions becomes a draft fiche.
 *  - Use: published fiches whose title or aliases appear in a call's material
 *    are added to its system prompt, with the parts that call can use, within
 *    a budget per action (curation, podcast, co-author, co-pilot, QA gate,
 *    social copy, Shorts).
 *  - Alerts: the Hub lists the policy files in the day's pool with their
 *    contested claims, and the claims the last brief repeated as fact.
 *
 * Storage: one non-autoloaded option keyed by slug, plus small options for
 * new-topic candidates and alerts. Admin: Policy Fiches -> Policies.
 *
 * @package PressHub_AI_Editor
 * @since 2.37.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Policy_Fiches {

    const OPTION            = 'presshub_ai_policy_fiches';
    const CANDIDATES_OPTION = 'presshub_ai_policy_candidates';
    const ALERTS_OPTION     = 'presshub_ai_policy_alerts';
    const DRAFT_STATE       = 'presshub_ai_policy_draft_state';
    const PAGE_SLUG         = 'presshub-ai-policies';
    const NONCE             = 'presshub_ai_policy';
    const CRON_HOOK         = 'presshub_ai_policy_update';
    const DRAFT_HOOK        = 'presshub_ai_policy_drafts';

    const POST_CREATE     = 'presshub_ai_policy_create';
    const POST_SAVE       = 'presshub_ai_policy_save';
    const POST_RECORD     = 'presshub_ai_policy_record';
    const POST_SUGGESTION = 'presshub_ai_policy_suggestion';
    const POST_DELETE     = 'presshub_ai_policy_delete';
    const POST_DRAFT      = 'presshub_ai_policy_draft';
    const POST_EXAMPLE    = 'presshub_ai_policy_example';

    const MAX_FICHES       = 60;
    const MAX_ALIASES      = 12;
    const MAX_FACTS        = 20;
    const MAX_CLAIMS       = 40;
    const MAX_TIMELINE     = 40;
    const MAX_ACTORS       = 20;
    const MAX_QUESTIONS    = 15;
    const MAX_SUGGESTIONS  = 15;
    const HISTORY_MAX      = 10;
    const MAX_INJECTED     = 3;
    const MAX_PER_UPDATE   = 6;
    const MAX_DRAFTS       = 8;
    const NEW_TOPIC_EDITIONS = 3;
    const TEXT_MAX         = 1500;

    const STATUSES       = [ 'draft', 'published' ];
    const CLAIM_STATUSES = [ 'unchecked', 'contested', 'confirmed', 'false' ];

    /**
     * Character budget per action. Exact keys first, then prefixes ending in
     * "_". Filter: presshub_ai_policy_fiches_budgets.
     */
    const BUDGETS = [
        'briefing_curation'    => 3000,
        'podcast_script'       => 2000,
        'coauthor_draft'       => 2500,
        'copilot_chat'         => 2500,
        'coauthor_scorecard'   => 1500,
        'social_tiktok_shorts' => 800,
        'social_copy_'         => 800,
    ];

    /** Which parts each kind of call gets (see parts_for()). */
    const PARTS = [
        'full'    => [ 'principles', 'framing', 'facts', 'claims', 'questions' ],
        'podcast' => [ 'principles', 'framing', 'claims' ],
        'qa'      => [ 'framing', 'claims' ],
        'social'  => [ 'framing' ],
    ];

    private static ?array $last_applied = null;

    // ---------------------------------------------------------------------
    // Hooks
    // ---------------------------------------------------------------------

    public static function register_hooks(): void {
        add_action( 'presshub_ai_memory_digest_built', [ __CLASS__, 'schedule_update' ], 20, 1 );
        add_action( self::CRON_HOOK, [ __CLASS__, 'handle_update_cron' ], 10, 1 );
        add_action( self::DRAFT_HOOK, [ __CLASS__, 'handle_draft_cron' ], 10, 0 );
        foreach ( [ self::POST_CREATE, self::POST_SAVE, self::POST_RECORD, self::POST_SUGGESTION, self::POST_DELETE, self::POST_DRAFT, self::POST_EXAMPLE ] as $action ) {
            add_action( 'admin_post_' . $action, [ __CLASS__, 'handle_' . substr( $action, strlen( 'presshub_ai_policy_' ) ) ] );
        }
    }

    public static function is_enabled(): bool {
        // #233: the Editorial knowledge switch (default on) sits under Editorial memory.
        $on = class_exists( 'PressHub_AI_Settings_Storage' ) ? ( PressHub_AI_Settings_Storage::get_memory_enabled() && PressHub_AI_Settings_Storage::get_policy_fiches_enabled() ) : true;
        return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'presshub_ai_policy_fiches_enabled', $on ) : $on;
    }

    // ---------------------------------------------------------------------
    // Text helpers
    // ---------------------------------------------------------------------

    public static function fold( string $s ): string {
        return PressHub_AI_Stakeholders::fold( $s );
    }

    public static function slug( string $title ): string {
        $slug = str_replace( ' ', '-', self::fold( $title ) );
        return '' !== $slug ? self::clip( $slug, 80 ) : '';
    }

    private static function clip( string $s, int $max ): string {
        $s = trim( preg_replace( '/[ \t]+/u', ' ', $s ) ?? $s );
        return ( function_exists( 'mb_strlen' ) && mb_strlen( $s ) > $max ) ? rtrim( mb_substr( $s, 0, $max ) ) : $s;
    }

    private static function line( string $s, int $max ): string {
        return self::clip( preg_replace( '/\s+/u', ' ', $s ) ?? $s, $max );
    }

    private static function len( string $s ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
    }

    private static function today(): string {
        return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
    }

    private static function now(): string {
        return function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
    }

    private static function valid_date( string $d ): string {
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
    }

    private static function new_id(): string {
        return substr( md5( uniqid( '', true ) . ( function_exists( 'wp_rand' ) ? wp_rand() : mt_rand() ) ), 0, 10 );
    }

    // ---------------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------------

    public static function empty_fiche( string $title = '' ): array {
        return [
            'slug'        => self::slug( $title ),
            'title'       => $title,
            'aliases'     => [],
            'status'      => 'draft',
            'locked'      => false,
            'origin'      => 'generated',
            'principles'  => '',
            'framing'     => '',
            'facts'       => [],
            'claims'      => [],
            'timeline'    => [],
            'actors'      => [],
            'questions'   => [],
            'suggestions' => [],
            'history'     => [],
            'version'     => 0,
            'created_at'  => '',
            'updated_at'  => '',
            'updated_by'  => 0,
            'last_seen'   => '',
            'mentions'    => 0,
        ];
    }

    public static function normalize( array $f ): array {
        $out          = array_merge( self::empty_fiche(), array_intersect_key( $f, self::empty_fiche() ) );
        $out['title'] = self::line( (string) $out['title'], 120 );
        $out['slug']  = '' !== (string) $out['slug'] ? self::clip( (string) $out['slug'], 80 ) : self::slug( $out['title'] );

        $aliases = [];
        foreach ( (array) $out['aliases'] as $a ) {
            $a = self::line( (string) $a, 80 );
            if ( '' !== $a && ! in_array( $a, $aliases, true ) ) {
                $aliases[] = $a;
            }
        }
        $out['aliases']    = array_slice( $aliases, 0, self::MAX_ALIASES );
        $out['status']     = in_array( $out['status'], self::STATUSES, true ) ? $out['status'] : 'draft';
        $out['locked']     = ! empty( $out['locked'] );
        $out['origin']     = 'edited' === $out['origin'] ? 'edited' : 'generated';
        $out['principles'] = self::clip( (string) $out['principles'], self::TEXT_MAX );
        $out['framing']    = self::clip( (string) $out['framing'], self::TEXT_MAX );

        $facts = [];
        foreach ( (array) $out['facts'] as $x ) {
            $x    = is_array( $x ) ? $x : [ 'text' => (string) $x ];
            $text = self::line( (string) ( $x['text'] ?? '' ), 300 );
            if ( '' !== $text ) {
                $facts[] = [ 'text' => $text, 'url' => esc_url_raw( (string) ( $x['url'] ?? '' ) ) ];
            }
        }
        $out['facts'] = array_slice( $facts, 0, self::MAX_FACTS );

        $claims = [];
        foreach ( (array) $out['claims'] as $c ) {
            if ( ! is_array( $c ) || '' === trim( (string) ( $c['claim'] ?? '' ) ) ) {
                continue;
            }
            $status   = (string) ( $c['status'] ?? 'unchecked' );
            $claims[] = [
                'id'      => '' !== (string) ( $c['id'] ?? '' ) ? sanitize_key( (string) $c['id'] ) : self::new_id(),
                'date'    => self::valid_date( (string) ( $c['date'] ?? '' ) ),
                'claim'   => self::line( (string) $c['claim'], 300 ),
                'by'      => self::line( (string) ( $c['by'] ?? '' ), 120 ),
                'url'     => esc_url_raw( (string) ( $c['url'] ?? '' ) ),
                'status'  => in_array( $status, self::CLAIM_STATUSES, true ) ? $status : 'unchecked',
                'reason'  => self::line( (string) ( $c['reason'] ?? '' ), 300 ),
                'counter' => self::line( (string) ( $c['counter'] ?? '' ), 400 ),
                'origin'  => 'editor' === ( $c['origin'] ?? '' ) ? 'editor' : 'auto',
                'repeated_as_fact' => ! empty( $c['repeated_as_fact'] ),
            ];
        }
        $out['claims'] = $claims;

        $timeline = [];
        foreach ( (array) $out['timeline'] as $t ) {
            if ( is_array( $t ) && '' !== trim( (string) ( $t['text'] ?? '' ) ) ) {
                $timeline[] = [
                    'date' => self::valid_date( (string) ( $t['date'] ?? '' ) ),
                    'text' => self::line( (string) $t['text'], 300 ),
                    'url'  => esc_url_raw( (string) ( $t['url'] ?? '' ) ),
                ];
            }
        }
        usort( $timeline, static function ( $a, $b ) {
            return strcmp( $b['date'], $a['date'] );
        } );
        $out['timeline'] = array_slice( $timeline, 0, self::MAX_TIMELINE );

        $actors = [];
        foreach ( (array) $out['actors'] as $a ) {
            $a = self::line( (string) $a, 100 );
            if ( '' !== $a && ! in_array( self::fold( $a ), array_map( [ __CLASS__, 'fold' ], $actors ), true ) ) {
                $actors[] = $a;
            }
        }
        $out['actors'] = array_slice( $actors, 0, self::MAX_ACTORS );

        $questions = [];
        foreach ( (array) $out['questions'] as $q ) {
            $q = is_array( $q ) ? $q : [ 'text' => (string) $q ];
            if ( '' === trim( (string) ( $q['text'] ?? '' ) ) ) {
                continue;
            }
            $questions[] = [
                'id'     => '' !== (string) ( $q['id'] ?? '' ) ? sanitize_key( (string) $q['id'] ) : self::new_id(),
                'text'   => self::line( (string) $q['text'], 300 ),
                'date'   => self::valid_date( (string) ( $q['date'] ?? '' ) ),
                'status' => 'answered' === ( $q['status'] ?? '' ) ? 'answered' : 'open',
                'answer' => self::line( (string) ( $q['answer'] ?? '' ), 400 ),
            ];
        }
        $out['questions'] = $questions;

        $suggestions = [];
        foreach ( (array) $out['suggestions'] as $s ) {
            if ( ! is_array( $s ) || '' === trim( (string) ( $s['text'] ?? '' ) ) ) {
                continue;
            }
            $field         = (string) ( $s['field'] ?? 'facts' );
            $suggestions[] = [
                'id'     => '' !== (string) ( $s['id'] ?? '' ) ? sanitize_key( (string) $s['id'] ) : self::new_id(),
                'date'   => self::valid_date( (string) ( $s['date'] ?? '' ) ),
                'field'  => in_array( $field, [ 'facts', 'framing', 'principles', 'aliases' ], true ) ? $field : 'facts',
                'text'   => self::line( (string) $s['text'], 400 ),
                'reason' => self::line( (string) ( $s['reason'] ?? '' ), 300 ),
                'url'    => esc_url_raw( (string) ( $s['url'] ?? '' ) ),
            ];
        }
        $out['suggestions'] = array_slice( $suggestions, -self::MAX_SUGGESTIONS );

        $out['history']    = array_slice( array_values( array_filter( (array) $out['history'], 'is_array' ) ), 0, self::HISTORY_MAX );
        $out['version']    = max( 0, (int) $out['version'] );
        $out['updated_by'] = (int) $out['updated_by'];
        $out['mentions']   = max( 0, (int) $out['mentions'] );
        $out['last_seen']  = self::valid_date( (string) $out['last_seen'] );
        foreach ( [ 'created_at', 'updated_at' ] as $k ) {
            $out[ $k ] = (string) $out[ $k ];
        }
        $out['claims'] = self::cap_claims( $out['claims'] );
        $out['questions'] = self::cap_questions( $out['questions'] );
        return $out;
    }

    /** Keep editor claims and contested/false ones first, then the newest. */
    private static function cap_claims( array $claims ): array {
        if ( count( $claims ) <= self::MAX_CLAIMS ) {
            return $claims;
        }
        $rank = static function ( $c ) {
            return ( 'editor' === $c['origin'] || in_array( $c['status'], [ 'contested', 'false', 'confirmed' ], true ) ) ? 1 : 0;
        };
        $idx = array_keys( $claims );
        usort( $idx, static function ( $a, $b ) use ( $claims, $rank ) {
            return [ $rank( $claims[ $b ] ), $claims[ $b ]['date'], $b ] <=> [ $rank( $claims[ $a ] ), $claims[ $a ]['date'], $a ];
        } );
        $keep = array_flip( array_slice( $idx, 0, self::MAX_CLAIMS ) );
        return array_values( array_intersect_key( $claims, $keep ) );
    }

    /** Keep all open questions (newest first when over the cap), then answered ones. */
    private static function cap_questions( array $questions ): array {
        if ( count( $questions ) <= self::MAX_QUESTIONS ) {
            return $questions;
        }
        $open     = array_values( array_filter( $questions, static function ( $q ) { return 'open' === $q['status']; } ) );
        $answered = array_values( array_filter( $questions, static function ( $q ) { return 'open' !== $q['status']; } ) );
        return array_slice( array_merge( array_slice( $open, -self::MAX_QUESTIONS ), array_reverse( $answered ) ), 0, self::MAX_QUESTIONS );
    }

    public static function all(): array {
        $raw = get_option( self::OPTION, [] );
        $out = [];
        foreach ( is_array( $raw ) ? $raw : [] as $slug => $f ) {
            if ( is_array( $f ) && '' !== trim( (string) ( $f['title'] ?? '' ) ) ) {
                $f['slug']             = (string) $slug;
                $out[ (string) $slug ] = self::normalize( $f );
            }
        }
        return $out;
    }

    public static function get( string $slug ): ?array {
        $all = self::all();
        return $all[ $slug ] ?? null;
    }

    private static function store( array $fiches ): void {
        update_option( self::OPTION, array_slice( $fiches, 0, self::MAX_FICHES, true ), false );
    }

    private static function put( array $fiche ): void {
        $all                    = self::all();
        $all[ $fiche['slug'] ]  = self::normalize( $fiche );
        self::store( $all );
    }

    /** Slug of the fiche whose title or alias equals one of $names (folded), or ''. */
    public static function find_slug( array $fiches, array $names ): string {
        $keys = array_filter( array_map( [ __CLASS__, 'fold' ], $names ), 'strlen' );
        foreach ( $fiches as $slug => $f ) {
            $own = array_filter( array_map( [ __CLASS__, 'fold' ], array_merge( [ $f['title'] ], $f['aliases'] ) ), 'strlen' );
            if ( array_intersect( $keys, $own ) ) {
                return (string) $slug;
            }
        }
        return '';
    }

    /** Create a fiche; returns its slug or WP_Error. */
    public static function create( string $title, array $data = [], int $user_id = 0 ) {
        $title = self::line( $title, 120 );
        $slug  = self::slug( $title );
        if ( '' === $slug ) {
            return new WP_Error( 'policy_title_required', __( 'A policy fiche needs a title.', 'presshub-ai-editor' ) );
        }
        $all = self::all();
        if ( isset( $all[ $slug ] ) || '' !== self::find_slug( $all, array_merge( [ $title ], (array) ( $data['aliases'] ?? [] ) ) ) ) {
            return new WP_Error( 'policy_exists', __( 'A fiche for this policy file already exists.', 'presshub-ai-editor' ) );
        }
        if ( count( $all ) >= self::MAX_FICHES ) {
            return new WP_Error( 'policy_limit', __( 'The maximum number of policy fiches is reached.', 'presshub-ai-editor' ) );
        }
        $fiche = array_merge( self::empty_fiche( $title ), $data, [
            'slug'       => $slug,
            'title'      => $title,
            'created_at' => self::now(),
            'updated_at' => self::now(),
            'updated_by' => $user_id,
        ] );
        $all[ $slug ] = self::normalize( $fiche );
        self::store( $all );
        return $slug;
    }

    /**
     * Editor save of the core. The previous core goes to the history.
     *
     * @return array|WP_Error The saved fiche.
     */
    public static function save_core( string $slug, array $data, int $user_id = 0 ) {
        $f = self::get( $slug );
        if ( null === $f ) {
            return new WP_Error( 'policy_not_found', __( 'Policy fiche not found.', 'presshub-ai-editor' ) );
        }
        $title = self::line( (string) ( $data['title'] ?? $f['title'] ), 120 );
        if ( '' === $title ) {
            return new WP_Error( 'policy_title_required', __( 'A policy fiche needs a title.', 'presshub-ai-editor' ) );
        }
        array_unshift( $f['history'], [
            'version'    => $f['version'],
            'at'         => $f['updated_at'],
            'by'         => $f['updated_by'],
            'title'      => $f['title'],
            'aliases'    => $f['aliases'],
            'principles' => $f['principles'],
            'framing'    => $f['framing'],
            'facts'      => $f['facts'],
            'status'     => $f['status'],
        ] );
        $f['title']      = $title;
        $f['aliases']    = isset( $data['aliases'] ) ? (array) $data['aliases'] : $f['aliases'];
        $f['principles'] = (string) ( $data['principles'] ?? $f['principles'] );
        $f['framing']    = (string) ( $data['framing'] ?? $f['framing'] );
        $f['facts']      = isset( $data['facts'] ) ? (array) $data['facts'] : $f['facts'];
        $f['actors']     = isset( $data['actors'] ) ? (array) $data['actors'] : $f['actors'];
        $f['status']     = (string) ( $data['status'] ?? $f['status'] );
        $f['locked']     = ! empty( $data['locked'] );
        $f['origin']     = 'edited';
        $f['version']    = $f['version'] + 1;
        $f['updated_at'] = self::now();
        $f['updated_by'] = $user_id;
        self::put( $f );
        self::audit( 'policy_fiche_saved', $slug, $user_id );
        return self::get( $slug );
    }

    /**
     * Editor changes to the running record: claim status/counter, removals,
     * a new claim, answered questions, a new question.
     *
     * @return array|WP_Error
     */
    public static function save_record( string $slug, array $data, int $user_id = 0 ) {
        $f = self::get( $slug );
        if ( null === $f ) {
            return new WP_Error( 'policy_not_found', __( 'Policy fiche not found.', 'presshub-ai-editor' ) );
        }
        $edits  = is_array( $data['claims'] ?? null ) ? $data['claims'] : [];
        $claims = [];
        foreach ( $f['claims'] as $c ) {
            $e = is_array( $edits[ $c['id'] ] ?? null ) ? $edits[ $c['id'] ] : null;
            if ( null !== $e ) {
                if ( ! empty( $e['remove'] ) ) {
                    continue;
                }
                $status = (string) ( $e['status'] ?? $c['status'] );
                if ( in_array( $status, self::CLAIM_STATUSES, true ) && $status !== $c['status'] ) {
                    $c['status'] = $status;
                    $c['origin'] = 'editor';
                }
                if ( isset( $e['counter'] ) && (string) $e['counter'] !== $c['counter'] ) {
                    $c['counter'] = (string) $e['counter'];
                    $c['origin']  = 'editor';
                }
            }
            $claims[] = $c;
        }
        $new = is_array( $data['new_claim'] ?? null ) ? $data['new_claim'] : [];
        if ( '' !== trim( (string) ( $new['claim'] ?? '' ) ) ) {
            $claims[] = [
                'id'      => self::new_id(),
                'date'    => self::today(),
                'claim'   => (string) $new['claim'],
                'by'      => (string) ( $new['by'] ?? '' ),
                'status'  => (string) ( $new['status'] ?? 'unchecked' ),
                'counter' => (string) ( $new['counter'] ?? '' ),
                'origin'  => 'editor',
            ];
        }
        $f['claims'] = $claims;

        $answered  = is_array( $data['questions'] ?? null ) ? $data['questions'] : [];
        $questions = [];
        foreach ( $f['questions'] as $q ) {
            $e = is_array( $answered[ $q['id'] ] ?? null ) ? $answered[ $q['id'] ] : null;
            if ( null !== $e && ! empty( $e['remove'] ) ) {
                continue;
            }
            if ( null !== $e ) {
                $q['status'] = ! empty( $e['answered'] ) ? 'answered' : 'open';
                if ( isset( $e['answer'] ) ) {
                    $q['answer'] = (string) $e['answer'];
                }
            }
            $questions[] = $q;
        }
        if ( '' !== trim( (string) ( $data['new_question'] ?? '' ) ) ) {
            $questions[] = [ 'text' => (string) $data['new_question'], 'date' => self::today(), 'status' => 'open' ];
        }
        $f['questions']  = $questions;
        $f['updated_at'] = self::now();
        $f['updated_by'] = $user_id;
        self::put( $f );
        self::audit( 'policy_record_saved', $slug, $user_id );
        return self::get( $slug );
    }

    /** Accept or dismiss a pending core suggestion. */
    public static function resolve_suggestion( string $slug, string $id, bool $accept, int $user_id = 0 ) {
        $f = self::get( $slug );
        if ( null === $f ) {
            return new WP_Error( 'policy_not_found', __( 'Policy fiche not found.', 'presshub-ai-editor' ) );
        }
        $found = null;
        foreach ( $f['suggestions'] as $i => $s ) {
            if ( $s['id'] === $id ) {
                $found = $s;
                unset( $f['suggestions'][ $i ] );
                break;
            }
        }
        if ( null === $found ) {
            return new WP_Error( 'policy_suggestion_not_found', __( 'Suggestion not found.', 'presshub-ai-editor' ) );
        }
        $f['suggestions'] = array_values( $f['suggestions'] );
        self::put( $f );
        if ( ! $accept ) {
            return self::get( $slug );
        }
        $core = [];
        if ( 'facts' === $found['field'] ) {
            $core['facts'] = array_merge( $f['facts'], [ [ 'text' => $found['text'], 'url' => $found['url'] ] ] );
        } elseif ( 'aliases' === $found['field'] ) {
            $core['aliases'] = array_merge( $f['aliases'], [ $found['text'] ] );
        } else {
            $field          = $found['field'];
            $core[ $field ] = trim( $f[ $field ] . "\n" . $found['text'] );
        }
        $core['status'] = $f['status'];
        $core['locked'] = $f['locked'];
        return self::save_core( $slug, $core, $user_id );
    }

    public static function delete( string $slug, int $user_id = 0 ): bool {
        $all = self::all();
        if ( ! isset( $all[ $slug ] ) ) {
            return false;
        }
        unset( $all[ $slug ] );
        self::store( $all );
        self::audit( 'policy_fiche_deleted', $slug, $user_id );
        return true;
    }

    private static function audit( string $event, string $slug, int $user_id ): void {
        if ( class_exists( 'PressHub_AI_Audit_Logger' ) && method_exists( 'PressHub_AI_Audit_Logger', 'log' ) ) {
            try {
                PressHub_AI_Audit_Logger::log( $event, 'policy_fiche', $slug, [ 'user_id' => $user_id ] );
            } catch ( \Throwable $e ) {
                // Auditing never blocks an edit.
            }
        }
    }

    // ---------------------------------------------------------------------
    // Matching and injection
    // ---------------------------------------------------------------------

    public static function budget_for( string $action ): int {
        $budgets = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_context_budgets( 'presshub_ai_policy_fiches_budgets', self::BUDGETS ) : self::BUDGETS;
        if ( function_exists( 'apply_filters' ) ) {
            $filtered = apply_filters( 'presshub_ai_policy_fiches_budgets', $budgets );
            $budgets  = is_array( $filtered ) ? $filtered : $budgets;
        }
        if ( isset( $budgets[ $action ] ) ) {
            return max( 0, (int) $budgets[ $action ] );
        }
        foreach ( $budgets as $key => $budget ) {
            $key = (string) $key;
            if ( '_' === substr( $key, -1 ) && 0 === strpos( $action, $key ) ) {
                return max( 0, (int) $budget );
            }
        }
        return 0;
    }

    /** The parts an action gets. */
    public static function parts_for( string $action ): array {
        if ( 'coauthor_scorecard' === $action ) {
            return self::PARTS['qa'];
        }
        if ( 'podcast_script' === $action ) {
            return self::PARTS['podcast'];
        }
        if ( 'social_tiktok_shorts' === $action || 0 === strpos( $action, 'social_copy_' ) ) {
            return self::PARTS['social'];
        }
        return self::PARTS['full'];
    }

    /**
     * Regex for a topic phrase in folded text. Greek nouns change more than
     * names (συντάξεις / συντάξεων, μεταρρύθμιση / μεταρρυθμίσεις), so up to
     * three trailing vowels, σ or ν are cut (keeping a stem of 4+ letters) and
     * up to five letters may follow. One word shorter than 6 letters never
     * matches on its own.
     */
    public static function topic_pattern( string $phrase ): string {
        $words = array_values( array_filter( explode( ' ', self::fold( $phrase ) ), 'strlen' ) );
        if ( empty( $words ) || ( 1 === count( $words ) && self::len( $words[0] ) < 6 ) ) {
            return '';
        }
        $parts = [];
        foreach ( $words as $w ) {
            $stem = $w;
            for ( $i = 0; $i < 3 && self::len( $stem ) > 4 && preg_match( '/[αεηιουωσν]$/u', $stem ); $i++ ) {
                $stem = mb_substr( $stem, 0, -1 );
            }
            $parts[] = preg_quote( $stem, '/' ) . '\p{L}{0,5}';
        }
        return '/(?<!\p{L})' . implode( '\s+', $parts ) . '(?!\p{L})/u';
    }

    /** Regexes for a fiche's title and aliases in folded text. */
    private static function patterns( array $f ): array {
        $out = [];
        foreach ( array_merge( [ $f['title'] ], $f['aliases'] ) as $n ) {
            $re = self::topic_pattern( (string) $n );
            if ( '' !== $re ) {
                $out[] = $re;
            }
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * Fiches whose title or aliases appear in $text, most mentioned first.
     *
     * @param bool $published_only Drafts are never injected; the updater also matches drafts.
     * @return array<string,array{count:int,fiche:array}>
     */
    public static function find_in_text( string $text, ?array $fiches = null, bool $published_only = true ): array {
        $folded = self::fold( $text );
        $hits   = [];
        foreach ( ( $fiches ?? self::all() ) as $slug => $f ) {
            if ( $published_only && 'published' !== $f['status'] ) {
                continue;
            }
            $count = 0;
            foreach ( self::patterns( $f ) as $re ) {
                $count += (int) preg_match_all( $re, $folded );
            }
            if ( $count > 0 ) {
                $hits[ (string) $slug ] = [ 'count' => $count, 'fiche' => $f ];
            }
        }
        uasort( $hits, static function ( $a, $b ) {
            return [ $b['count'], $b['fiche']['mentions'] ] <=> [ $a['count'], $a['fiche']['mentions'] ];
        } );
        return $hits;
    }

    private static function claim_tag( string $status ): string {
        $tags = [
            'unchecked' => 'μη ελεγμένος',
            'contested' => 'αμφισβητούμενος',
            'confirmed' => 'επιβεβαιωμένος',
            'false'     => 'ανακριβής',
        ];
        return $tags[ $status ] ?? $status;
    }

    /** Claims worth sending: false and contested first, then unchecked, newest first. */
    public static function claims_to_send( array $f, int $max = 6 ): array {
        $claims = array_values( array_filter( $f['claims'], static function ( $c ) {
            return 'confirmed' !== $c['status'];
        } ) );
        $rank = [ 'false' => 3, 'contested' => 2, 'unchecked' => 1 ];
        usort( $claims, static function ( $a, $b ) use ( $rank ) {
            return [ $rank[ $b['status'] ] ?? 0, $b['date'] ] <=> [ $rank[ $a['status'] ] ?? 0, $a['date'] ];
        } );
        return array_slice( $claims, 0, $max );
    }

    /** One fiche as prompt text, with the parts listed (in that order). */
    public static function render_fiche( array $f, array $parts ): string {
        $lines = [ '## ' . $f['title'] ];
        foreach ( $parts as $part ) {
            if ( 'principles' === $part && '' !== $f['principles'] ) {
                $lines[] = 'Αρχές: ' . str_replace( "\n", "\n  ", $f['principles'] );
            } elseif ( 'framing' === $part && '' !== $f['framing'] ) {
                $lines[] = 'Πλαισίωση: ' . str_replace( "\n", "\n  ", $f['framing'] );
            } elseif ( 'facts' === $part && ! empty( $f['facts'] ) ) {
                $lines[] = 'Επιβεβαιωμένα στοιχεία:';
                foreach ( $f['facts'] as $x ) {
                    $lines[] = '- ' . $x['text'];
                }
            } elseif ( 'claims' === $part ) {
                $claims = self::claims_to_send( $f );
                if ( ! empty( $claims ) ) {
                    $lines[] = 'Ισχυρισμοί προς έλεγχο:';
                    foreach ( $claims as $c ) {
                        $line = '- [' . self::claim_tag( $c['status'] ) . '] «' . $c['claim'] . '»';
                        if ( '' !== $c['by'] ) {
                            $line .= ' — ' . $c['by'];
                        }
                        if ( '' !== $c['date'] ) {
                            $line .= ', ' . $c['date'];
                        }
                        if ( '' !== $c['counter'] ) {
                            $line .= '. Αντίλογος: ' . $c['counter'];
                        }
                        $lines[] = $line;
                    }
                }
            } elseif ( 'questions' === $part ) {
                $open = array_values( array_filter( $f['questions'], static function ( $q ) { return 'open' === $q['status']; } ) );
                if ( ! empty( $open ) ) {
                    $lines[] = 'Ανοιχτά ερωτήματα (αν το υλικό τα απαντά, ανέφερέ το· αλλιώς μπορείς να τα θέσεις):';
                    foreach ( array_slice( $open, -4 ) as $q ) {
                        $lines[] = '- ' . $q['text'];
                    }
                }
            }
        }
        return count( $lines ) > 1 ? implode( "\n", $lines ) . "\n" : '';
    }

    /**
     * The block for an action, within its budget: fiches in order; each one
     * full, else with fewer parts, else left out.
     */
    public static function render( array $hits, string $action, int $budget ): array {
        if ( $budget <= 0 || empty( $hits ) ) {
            return [ 'text' => '', 'slugs' => [] ];
        }
        if ( 'coauthor_scorecard' === $action ) {
            $header = "=== ΕΛΕΓΧΟΣ ΜΕ ΒΑΣΗ ΤΟΥΣ ΦΑΚΕΛΟΥΣ ΠΟΛΙΤΙΚΩΝ ===\n"
                . "Έλεγξε επιπλέον αν το κείμενο παρουσιάζει ως γεγονός κάποιον από τους παρακάτω αμφισβητούμενους, μη ελεγμένους ή ανακριβείς ισχυρισμούς χωρίς απόδοση και χωρίς τον αντίλογο, ή αν παραβιάζει τους κανόνες πλαισίωσης. Αν ναι, μείωσε τη βαθμολογία και γράψε στο feedback ποιον ισχυρισμό ή κανόνα αφορά.\n";
        } else {
            $header = "=== ΦΑΚΕΛΟΙ ΠΟΛΙΤΙΚΩΝ (συντακτική γραμμή) ===\n"
                . "Οδηγίες του αρχισυντάκτη για τα θέματα πολιτικής που εμφανίζονται στο υλικό. Οι αρχές και οι κανόνες πλαισίωσης είναι δεσμευτικοί. Τα επιβεβαιωμένα στοιχεία μπορείς να τα χρησιμοποιείς ως υπόβαθρο. Τους αμφισβητούμενους και μη ελεγμένους ισχυρισμούς απόδωσέ τους σε όποιον τους διατυπώνει και δώσε τον αντίλογο. Τους ανακριβείς ισχυρισμούς μην τους αναπαράγεις ως αληθινούς. Τίποτα από τους φακέλους δεν είναι σημερινή είδηση αν δεν υπάρχει στο σημερινό υλικό.\n";
        }
        $footer = '=== ΤΕΛΟΣ ΦΑΚΕΛΩΝ ΠΟΛΙΤΙΚΩΝ ===';
        $parts  = self::parts_for( $action );
        $text   = $header;
        $slugs  = [];
        foreach ( array_slice( $hits, 0, self::MAX_INJECTED, true ) as $slug => $hit ) {
            $tries = [ $parts, array_values( array_diff( $parts, [ 'questions', 'facts' ] ) ), array_values( array_intersect( $parts, [ 'principles', 'framing' ] ) ) ];
            foreach ( $tries as $try ) {
                $block = '' !== implode( '', $try ) ? self::render_fiche( $hit['fiche'], $try ) : '';
                if ( '' !== $block && self::len( $text . $block . $footer ) <= $budget ) {
                    $text   .= $block;
                    $slugs[] = (string) $slug;
                    break;
                }
            }
        }
        return empty( $slugs ) ? [ 'text' => '', 'slugs' => [] ] : [ 'text' => $text . $footer, 'slugs' => $slugs ];
    }

    /**
     * Add the matching published fiches to $sys_prompt. $material is the text
     * matched against (the call's material: user prompt plus the system prompt
     * as built before the agenda and people fiches were added). Never throws.
     */
    public static function apply( $sys_prompt, $material, string $action ) {
        self::$last_applied = null;
        if ( ! is_string( $sys_prompt ) || ! is_string( $material ) ) {
            return $sys_prompt;
        }
        try {
            $budget = self::budget_for( $action );
            if ( $budget <= 0 || ! self::is_enabled() ) {
                return $sys_prompt;
            }
            $hits = self::find_in_text( $material );
            $out  = self::render( $hits, $action, $budget );
            if ( '' === $out['text'] ) {
                return $sys_prompt;
            }
            $all      = self::all();
            $prompt   = rtrim( $sys_prompt ) . "\n\n" . $out['text'];
            self::$last_applied = [
                'action'        => $action,
                'fiches'        => array_map( static function ( $s ) use ( $all ) {
                    return $s . '@v' . (int) ( $all[ $s ]['version'] ?? 0 );
                }, $out['slugs'] ),
                'chars'         => self::len( $out['text'] ),
                'original_hash' => md5( $sys_prompt ),
                'prompt'        => $prompt,
            ];
            return $prompt;
        } catch ( \Throwable $e ) {
            return $sys_prompt;
        }
    }

    public static function last_applied(): ?array {
        return self::$last_applied;
    }

    public static function clear_last_applied(): void {
        self::$last_applied = null;
    }

    // ---------------------------------------------------------------------
    // Shared AI-reply parsing
    // ---------------------------------------------------------------------

    public static function parse_json( $raw, string $key ) {
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }
        $text = trim( (string) $raw );
        $text = preg_replace( '/^```(?:json)?\s*|\s*```$/u', '', $text ) ?? $text;
        $data = json_decode( $text, true );
        if ( ! is_array( $data ) && preg_match( '/\{.*\}/su', $text, $m ) ) {
            $data = json_decode( $m[0], true );
        }
        if ( ! is_array( $data ) || ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
            return new WP_Error( 'policy_invalid_json', __( 'The reply did not contain the expected list.', 'presshub-ai-editor' ) );
        }
        return $data;
    }

    private static function call( string $kind, string $sys, string $user, array $meta, ?PressHub_AI_API_Client $client, array $record_extra = [] ) {
        if ( null === $client ) {
            $client = new PressHub_AI_API_Client( 'briefing_memory' );
        }
        if ( method_exists( $client, 'set_action' ) ) {
            $client->set_action( $kind );
        }
        $t0  = class_exists( 'PressHub_AI_Generation_Recorder' ) ? PressHub_AI_Generation_Recorder::begin_call() : microtime( true );
        $raw = $client->call_provider( $sys, $user, true, [], null, array_merge( [ 'action_trigger' => $kind ], $meta ) );
        $rec = 0;
        if ( class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
            $rec = (int) PressHub_AI_Generation_Recorder::finish_call( $t0, 'memory_policy', $client, $sys, $user, $raw, $record_extra );
        }
        return [ $raw, $rec ];
    }

    // ---------------------------------------------------------------------
    // After each brief: additions to the matched fiches
    // ---------------------------------------------------------------------

    public static function schedule_update( $post_id ): void {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || ! self::is_enabled() || ! function_exists( 'wp_schedule_single_event' ) ) {
            return;
        }
        if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::CRON_HOOK, [ $post_id ] ) ) {
            return;
        }
        wp_schedule_single_event( time() + 120, self::CRON_HOOK, [ $post_id ] );
    }

    public static function handle_update_cron( $post_id ): void {
        $res = self::update_from_post( (int) $post_id );
        if ( is_wp_error( $res ) && class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::warning( 'Policy fiche update failed', [ 'post_id' => (int) $post_id, 'error' => $res->get_error_message() ] );
        }
    }

    /** Compact view of a fiche for the update prompt. */
    private static function fiche_for_prompt( array $f ): array {
        return [
            'slug'       => $f['slug'],
            'title'      => $f['title'],
            'aliases'    => $f['aliases'],
            'principles' => $f['principles'],
            'framing'    => $f['framing'],
            'facts'      => array_column( $f['facts'], 'text' ),
            'claims'     => array_map( static function ( $c ) {
                return [ 'claim' => $c['claim'], 'by' => $c['by'], 'status' => $c['status'] ];
            }, array_slice( $f['claims'], -15 ) ),
            'timeline'   => array_column( array_slice( $f['timeline'], 0, 8 ), 'text' ),
            'actors'     => $f['actors'],
            'open_questions' => array_map( static function ( $q ) {
                return [ 'id' => $q['id'], 'text' => $q['text'] ];
            }, array_values( array_filter( $f['questions'], static function ( $q ) { return 'open' === $q['status']; } ) ) ),
        ];
    }

    /**
     * Ask for additions to the fiches the brief mentions, merge them, count
     * new topics and record alerts.
     *
     * @return array|WP_Error Stats plus 'record_id'.
     */
    public static function update_from_post( int $post_id, ?PressHub_AI_API_Client $api_client = null ) {
        if ( ! self::is_enabled() ) {
            return [ 'status' => 'skipped', 'reason' => 'disabled' ];
        }
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'post_not_found', __( 'Post not found.', 'presshub-ai-editor' ) );
        }
        $date    = (string) get_post_meta( $post_id, '_presshub_briefing_date', true );
        $date    = '' !== $date ? $date : substr( (string) ( $post->post_date ?? self::today() ), 0, 10 );
        $edition = 'noon' === (string) get_post_meta( $post_id, '_presshub_briefing_edition', true ) ? 'noon' : 'morning';
        $text    = trim( wp_strip_all_tags( (string) ( $post->post_content ?? '' ) ) );
        if ( '' === $text ) {
            return new WP_Error( 'empty_briefing_post', __( 'Briefing post content is empty.', 'presshub-ai-editor' ) );
        }

        $all     = self::all();
        $hits    = self::find_in_text( $text, $all, false );
        $matched = [];
        foreach ( array_slice( $hits, 0, self::MAX_PER_UPDATE, true ) as $slug => $hit ) {
            if ( ! $hit['fiche']['locked'] ) {
                $matched[ $slug ] = self::fiche_for_prompt( $hit['fiche'] );
            }
        }
        $edition_label = 'noon' === $edition ? 'Μεσημβρινή Ενημέρωση' : 'Πρωινή Ενημέρωση';
        $fiches_json   = empty( $matched ) ? '[]' : (string) wp_json_encode( array_values( $matched ), JSON_UNESCAPED_UNICODE );
        $sys           = str_replace( [ '{date}', '{edition}', '{fiches}' ], [ $date, $edition_label, $fiches_json ], PressHub_AI_Prompt_Loader::load( 'memory/policy_update.txt' ) );
        $user          = "Ημερομηνία: {$date}\nΈκδοση: {$edition_label}\n\nΚείμενο Ενημέρωσης:\n{$text}";

        [ $raw, $rec ] = self::call( 'memory_policy', $sys, $user, [ 'briefing_date' => $date, 'edition' => $edition ], $api_client, [
            'post_id' => $post_id,
            'inputs'  => [ 'source_post_id' => $post_id, 'fiches' => array_keys( $matched ) ],
            'config'  => [ 'edition' => $edition, 'step' => 'update' ],
        ] );
        $data = self::parse_json( $raw, 'fiches' );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $url   = function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '';
        $stats = self::merge_update( $data, $date, $date . '-' . $edition, $url, array_keys( $matched ) );
        $stats['record_id'] = $rec;
        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( 'Policy fiches updated', array_merge( $stats, [ 'post_id' => $post_id, 'date' => $date, 'edition' => $edition ] ) );
        }
        return $stats;
    }

    /**
     * Merge an update reply. Only the fiches that were sent ($allowed) are
     * touched; locked fiches never; the core never (it gets suggestions).
     */
    public static function merge_update( array $data, string $date, string $source, string $url = '', array $allowed = [] ): array {
        $all    = self::all();
        $stats  = [ 'fiches' => 0, 'timeline' => 0, 'claims' => 0, 'contested' => 0, 'questions' => 0, 'answered' => 0, 'suggestions' => 0, 'drafts' => 0 ];
        $alerts = [];
        foreach ( (array) ( $data['fiches'] ?? [] ) as $u ) {
            $slug = is_array( $u ) ? (string) ( $u['slug'] ?? '' ) : '';
            if ( '' === $slug || ! isset( $all[ $slug ] ) || ( ! empty( $allowed ) && ! in_array( $slug, $allowed, true ) ) || $all[ $slug ]['locked'] ) {
                continue;
            }
            $f       = $all[ $slug ];
            $changed = false;

            $known = array_map( [ __CLASS__, 'fold' ], array_column( $f['timeline'], 'text' ) );
            foreach ( array_slice( (array) ( $u['timeline'] ?? [] ), 0, 5 ) as $t ) {
                $t = is_array( $t ) ? (string) ( $t['text'] ?? '' ) : (string) $t;
                if ( '' !== trim( $t ) && ! in_array( self::fold( $t ), $known, true ) ) {
                    $f['timeline'][] = [ 'date' => $date, 'text' => $t, 'url' => $url ];
                    $known[]         = self::fold( $t );
                    $stats['timeline']++;
                    $changed = true;
                }
            }

            $known = array_map( [ __CLASS__, 'fold' ], array_column( $f['claims'], 'claim' ) );
            foreach ( array_slice( (array) ( $u['claims'] ?? [] ), 0, 6 ) as $c ) {
                if ( ! is_array( $c ) || '' === trim( (string) ( $c['claim'] ?? '' ) ) ) {
                    continue;
                }
                $status   = 'contested' === ( $c['status'] ?? '' ) ? 'contested' : 'unchecked';
                $repeated = ! empty( $c['repeated_as_fact'] );
                $key      = self::fold( (string) $c['claim'] );
                if ( in_array( $key, $known, true ) ) {
                    // Known claim: never change its status; only note a repeat as fact.
                    foreach ( $f['claims'] as $existing ) {
                        if ( self::fold( $existing['claim'] ) === $key && $repeated && in_array( $existing['status'], [ 'contested', 'false', 'unchecked' ], true ) ) {
                            $alerts[] = [ 'fiche' => $slug, 'title' => $f['title'], 'claim' => $existing['claim'], 'by' => $existing['by'], 'status' => $existing['status'] ];
                        }
                    }
                    continue;
                }
                $f['claims'][] = [
                    'date'    => $date,
                    'claim'   => (string) $c['claim'],
                    'by'      => (string) ( $c['by'] ?? '' ),
                    'url'     => $url,
                    'status'  => $status,
                    'reason'  => (string) ( $c['reason'] ?? '' ),
                    'counter' => (string) ( $c['counter'] ?? '' ),
                    'origin'  => 'auto',
                    'repeated_as_fact' => $repeated,
                ];
                $known[] = $key;
                $stats['claims']++;
                if ( 'contested' === $status ) {
                    $stats['contested']++;
                }
                if ( $repeated ) {
                    $alerts[] = [ 'fiche' => $slug, 'title' => $f['title'], 'claim' => (string) $c['claim'], 'by' => (string) ( $c['by'] ?? '' ), 'status' => $status ];
                }
                $changed = true;
            }

            $known = array_map( [ __CLASS__, 'fold' ], array_column( $f['questions'], 'text' ) );
            foreach ( array_slice( (array) ( $u['questions'] ?? [] ), 0, 3 ) as $q ) {
                $q = is_array( $q ) ? (string) ( $q['text'] ?? '' ) : (string) $q;
                if ( '' !== trim( $q ) && ! in_array( self::fold( $q ), $known, true ) ) {
                    $f['questions'][] = [ 'text' => $q, 'date' => $date, 'status' => 'open' ];
                    $known[]          = self::fold( $q );
                    $stats['questions']++;
                    $changed = true;
                }
            }
            foreach ( (array) ( $u['answered'] ?? [] ) as $a ) {
                $qid    = is_array( $a ) ? sanitize_key( (string) ( $a['id'] ?? '' ) ) : '';
                $answer = is_array( $a ) ? trim( (string) ( $a['answer'] ?? '' ) ) : '';
                foreach ( $f['questions'] as &$q ) {
                    if ( '' !== $qid && $q['id'] === $qid && 'open' === $q['status'] && '' !== $answer ) {
                        $q['status'] = 'answered';
                        $q['answer'] = '[' . $date . '] ' . $answer;
                        $stats['answered']++;
                        $changed = true;
                    }
                }
                unset( $q );
            }

            $f['actors'] = array_merge( $f['actors'], array_map( 'strval', array_slice( (array) ( $u['actors'] ?? [] ), 0, 5 ) ) );

            $known = array_map( [ __CLASS__, 'fold' ], array_column( $f['suggestions'], 'text' ) );
            foreach ( array_slice( (array) ( $u['suggestions'] ?? [] ), 0, 3 ) as $s ) {
                if ( is_array( $s ) && '' !== trim( (string) ( $s['text'] ?? '' ) ) && ! in_array( self::fold( (string) $s['text'] ), $known, true ) ) {
                    $f['suggestions'][] = [ 'date' => $date, 'field' => (string) ( $s['field'] ?? 'facts' ), 'text' => (string) $s['text'], 'reason' => (string) ( $s['reason'] ?? '' ), 'url' => $url ];
                    $stats['suggestions']++;
                    $changed = true;
                }
            }

            $f['last_seen'] = $date;
            $f['mentions']  = $f['mentions'] + 1;
            $all[ $slug ]   = self::normalize( $f );
            if ( $changed ) {
                $stats['fiches']++;
            }
        }
        self::store( $all );
        $stats['drafts'] = self::count_new_topics( (array) ( $data['new_topics'] ?? [] ), $source );
        self::record_alerts( $date, $alerts );
        $stats['alerts'] = count( $alerts );
        return $stats;
    }

    /**
     * Count topics no fiche covers; one seen in NEW_TOPIC_EDITIONS editions
     * becomes a draft fiche. Returns the number of drafts made.
     */
    public static function count_new_topics( array $topics, string $source ): int {
        $cands = get_option( self::CANDIDATES_OPTION, [] );
        $cands = is_array( $cands ) ? $cands : [];
        $made  = 0;
        foreach ( array_slice( $topics, 0, 3 ) as $t ) {
            $title   = is_array( $t ) ? self::line( (string) ( $t['title'] ?? '' ), 120 ) : '';
            $aliases = is_array( $t ) ? array_map( 'strval', (array) ( $t['aliases'] ?? [] ) ) : [];
            $key     = self::fold( $title );
            if ( '' === $key || '' !== self::find_slug( self::all(), array_merge( [ $title ], $aliases ) ) ) {
                continue;
            }
            // A candidate may have been counted under one of its aliases.
            foreach ( $cands as $ck => $c ) {
                if ( array_intersect( array_map( [ __CLASS__, 'fold' ], array_merge( [ $title ], $aliases ) ), array_map( [ __CLASS__, 'fold' ], array_merge( [ (string) ( $c['title'] ?? '' ) ], (array) ( $c['aliases'] ?? [] ) ) ) ) ) {
                    $key = (string) $ck;
                    break;
                }
            }
            $c            = is_array( $cands[ $key ] ?? null ) ? $cands[ $key ] : [ 'title' => $title, 'aliases' => [], 'seen' => [] ];
            $c['aliases'] = array_values( array_unique( array_merge( (array) $c['aliases'], $aliases ) ) );
            $c['seen']    = array_values( array_unique( array_merge( (array) $c['seen'], [ $source ] ) ) );
            if ( count( $c['seen'] ) >= self::NEW_TOPIC_EDITIONS ) {
                $res = self::create( (string) $c['title'], [ 'aliases' => array_slice( $c['aliases'], 0, self::MAX_ALIASES ), 'status' => 'draft', 'origin' => 'generated' ] );
                if ( ! is_wp_error( $res ) ) {
                    $made++;
                }
                unset( $cands[ $key ] );
                continue;
            }
            $cands[ $key ] = $c;
        }
        update_option( self::CANDIDATES_OPTION, array_slice( $cands, -50, null, true ), false );
        return $made;
    }

    private static function record_alerts( string $date, array $alerts ): void {
        $stored = get_option( self::ALERTS_OPTION, [] );
        $stored = is_array( $stored ) ? $stored : [];
        if ( ! empty( $alerts ) ) {
            $stored[ $date ] = array_slice( array_merge( (array) ( $stored[ $date ] ?? [] ), $alerts ), -20 );
        }
        krsort( $stored );
        update_option( self::ALERTS_OPTION, array_slice( $stored, 0, 7, true ), false );
    }

    public static function alerts_for( string $date ): array {
        $stored = get_option( self::ALERTS_OPTION, [] );
        return is_array( $stored[ $date ] ?? null ) ? $stored[ $date ] : [];
    }

    // ---------------------------------------------------------------------
    // First drafts from the agenda and the memory
    // ---------------------------------------------------------------------

    /** Material for the drafts call: agenda themes and recent memory stories. */
    public static function draft_material( int $days = 28 ): string {
        $lines = [];
        if ( class_exists( 'PressHub_AI_Editorial_Agenda' ) ) {
            $agenda = PressHub_AI_Editorial_Agenda::get();
            foreach ( PressHub_AI_Editorial_Agenda::active_themes( $agenda ) as $t ) {
                $lines[] = '- [ατζέντα, προτεραιότητα ' . $t['priority'] . '] ' . $t['title'] . ( '' !== $t['notes'] ? ': ' . $t['notes'] : '' );
            }
            if ( '' !== (string) $agenda['guidance'] ) {
                $lines[] = 'Μόνιμες οδηγίες: ' . $agenda['guidance'];
            }
        }
        $stories = [];
        if ( class_exists( 'PressHub_AI_Memory_Store' ) ) {
            $today = self::today();
            $from  = gmdate( 'Y-m-d', strtotime( $today . " -{$days} days" ) );
            foreach ( PressHub_AI_Memory_Store::get_entries_for_range( $from, $today, null, 'active' ) as $e ) {
                $content = json_decode( (string) ( $e['content_json'] ?? '' ), true );
                foreach ( (array) ( $content['stories'] ?? [] ) as $s ) {
                    if ( ! is_array( $s ) || '' === trim( (string) ( $s['title'] ?? '' ) ) ) {
                        continue;
                    }
                    $key = self::fold( (string) ( $s['key'] ?? $s['title'] ) );
                    $stories[ $key ]['title'] = (string) $s['title'];
                    $stories[ $key ]['n']     = ( $stories[ $key ]['n'] ?? 0 ) + 1;
                    $stories[ $key ]['dev'][] = '[' . (string) ( $e['period_start'] ?? '' ) . '] ' . self::line( (string) ( $s['development'] ?? '' ), 240 );
                }
            }
        }
        uasort( $stories, static function ( $a, $b ) {
            return $b['n'] <=> $a['n'];
        } );
        $mem = [];
        foreach ( array_slice( $stories, 0, 25 ) as $s ) {
            $mem[] = '- ' . $s['title'] . ' (' . $s['n'] . ' εμφανίσεις)' . "\n  " . implode( "\n  ", array_slice( $s['dev'], -4 ) );
        }
        return "Συντακτική ατζέντα:\n" . ( empty( $lines ) ? 'Καμία.' : implode( "\n", $lines ) )
            . "\n\nΣυντακτική μνήμη (θέματα των τελευταίων {$days} ημερών):\n" . ( empty( $mem ) ? 'Κανένα.' : implode( "\n", $mem ) );
    }

    public static function schedule_drafts(): bool {
        if ( ! function_exists( 'wp_schedule_single_event' ) ) {
            return false;
        }
        if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::DRAFT_HOOK ) ) {
            return true;
        }
        update_option( self::DRAFT_STATE, [ 'status' => 'queued', 'at' => self::now() ], false );
        wp_schedule_single_event( time() + 5, self::DRAFT_HOOK );
        return true;
    }

    public static function handle_draft_cron(): void {
        update_option( self::DRAFT_STATE, [ 'status' => 'running', 'at' => self::now() ], false );
        $res = self::build_drafts();
        update_option( self::DRAFT_STATE, is_wp_error( $res )
            ? [ 'status' => 'error', 'at' => self::now(), 'message' => $res->get_error_message() ]
            : [ 'status' => 'done', 'at' => self::now(), 'created' => (int) ( $res['created'] ?? 0 ) ], false );
    }

    /**
     * One AI call drafts fiches from the agenda and memory. Existing topics are
     * skipped; new ones are created as drafts (never injected until published).
     *
     * @return array|WP_Error
     */
    public static function build_drafts( ?PressHub_AI_API_Client $api_client = null ) {
        $existing = array_column( self::all(), 'title' );
        $sys      = str_replace(
            [ '{max}', '{existing}' ],
            [ (string) self::MAX_DRAFTS, empty( $existing ) ? 'κανένα' : implode( '; ', $existing ) ],
            PressHub_AI_Prompt_Loader::load( 'memory/policy_drafts.txt' )
        );
        $user = self::draft_material();
        [ $raw, $rec ] = self::call( 'memory_policy', $sys, $user, [], $api_client, [ 'config' => [ 'step' => 'drafts' ] ] );
        $data = self::parse_json( $raw, 'fiches' );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $created = 0;
        foreach ( array_slice( $data['fiches'], 0, self::MAX_DRAFTS ) as $d ) {
            if ( ! is_array( $d ) || '' === trim( (string) ( $d['title'] ?? '' ) ) ) {
                continue;
            }
            $claims = [];
            foreach ( (array) ( $d['claims'] ?? [] ) as $c ) {
                if ( is_array( $c ) ) {
                    $claims[] = [
                        'claim'   => (string) ( $c['claim'] ?? '' ),
                        'by'      => (string) ( $c['by'] ?? '' ),
                        'date'    => (string) ( $c['date'] ?? '' ),
                        'status'  => 'contested' === ( $c['status'] ?? '' ) ? 'contested' : 'unchecked',
                        'counter' => (string) ( $c['counter'] ?? '' ),
                    ];
                }
            }
            $res = self::create( (string) $d['title'], [
                'aliases'    => array_map( 'strval', (array) ( $d['aliases'] ?? [] ) ),
                'principles' => (string) ( $d['principles'] ?? '' ),
                'framing'    => (string) ( $d['framing'] ?? '' ),
                'facts'      => array_map( 'strval', (array) ( $d['facts'] ?? [] ) ),
                'claims'     => $claims,
                'timeline'   => array_values( array_filter( (array) ( $d['timeline'] ?? [] ), 'is_array' ) ),
                'actors'     => array_map( 'strval', (array) ( $d['actors'] ?? [] ) ),
                'questions'  => array_map( 'strval', (array) ( $d['questions'] ?? [] ) ),
                'status'     => 'draft',
                'origin'     => 'generated',
            ] );
            if ( ! is_wp_error( $res ) ) {
                $created++;
            }
        }
        return [ 'created' => $created, 'record_id' => $rec ];
    }

    // ---------------------------------------------------------------------
    // Hub alerts
    // ---------------------------------------------------------------------

    /**
     * Policy files in the day's pool with their claims to watch, plus the
     * claims the last brief of that day repeated as fact. '' when nothing.
     */
    public static function hub_alerts_html( array $articles, string $date ): string {
        if ( ! self::is_enabled() ) {
            return '';
        }
        $text = '';
        foreach ( $articles as $a ) {
            if ( is_array( $a ) ) {
                $text .= ' ' . (string) ( $a['title'] ?? '' ) . ' ' . (string) ( $a['content'] ?? '' );
            }
        }
        $hits     = '' !== trim( $text ) ? self::find_in_text( $text ) : [];
        $repeated = self::alerts_for( $date );
        if ( empty( $hits ) && empty( $repeated ) ) {
            return '';
        }
        $html = '<div class="presshub-policy-alerts" id="presshub-policy-alerts"><p class="description" style="margin: 8px 0 4px;"><strong>' . esc_html__( 'Policy files in this material', 'presshub-ai-editor' ) . '</strong> · '
            . esc_html__( 'The briefing gets these fiches and is told to attribute contested claims and give the other side.', 'presshub-ai-editor' ) . '</p>';
        if ( ! empty( $repeated ) ) {
            $html .= '<div class="presshub-policy-repeated" style="margin: 4px 0; color: #8a4b00;">⚠️ <strong>' . esc_html__( 'A brief of this day repeated these claims as fact:', 'presshub-ai-editor' ) . '</strong><ul style="margin: 5px 0 0 20px; list-style: disc;">';
            foreach ( array_slice( $repeated, 0, 8 ) as $r ) {
                $html .= '<li>' . esc_html( (string) ( $r['title'] ?? '' ) . ': «' . (string) ( $r['claim'] ?? '' ) . '»' . ( '' !== (string) ( $r['by'] ?? '' ) ? ' (' . $r['by'] . ')' : '' ) ) . '</li>';
            }
            $html .= '</ul></div>';
        }
        if ( ! empty( $hits ) ) {
            $html .= '<ul style="margin: 8px 0 0 20px; list-style: disc;">';
            foreach ( array_slice( $hits, 0, 6, true ) as $slug => $hit ) {
                $f      = $hit['fiche'];
                $watch  = array_values( array_filter( self::claims_to_send( $f, 3 ), static function ( $c ) {
                    return in_array( $c['status'], [ 'contested', 'false' ], true );
                } ) );
                $html  .= '<li><a href="' . esc_url( self::edit_url( (string) $slug ) ) . '"><strong>' . esc_html( $f['title'] ) . '</strong></a>';
                if ( ! empty( $watch ) ) {
                    $html .= '<ul style="margin: 2px 0 4px 18px; list-style: circle;">';
                    foreach ( $watch as $c ) {
                        $html .= '<li>' . esc_html( '[' . self::claim_tag( $c['status'] ) . '] «' . $c['claim'] . '»' . ( '' !== $c['counter'] ? ' — ' . $c['counter'] : '' ) ) . '</li>';
                    }
                    $html .= '</ul>';
                }
                $html .= '</li>';
            }
            $html .= '</ul>';
        }
        return $html . '</div>';
    }

    // ---------------------------------------------------------------------
    // Admin
    // ---------------------------------------------------------------------

    public static function capability(): string {
        return class_exists( 'PressHub_AI_Fiches_Admin' ) ? PressHub_AI_Fiches_Admin::capability() : 'edit_others_posts';
    }

    public static function page_url( array $args = [] ): string {
        return add_query_arg( array_merge( [ 'page' => self::PAGE_SLUG ], $args ), admin_url( 'admin.php' ) );
    }

    public static function edit_url( string $slug ): string {
        return self::page_url( [ 'fiche' => rawurlencode( $slug ) ] );
    }

    private static function guard(): void {
        if ( ! current_user_can( self::capability() ) ) {
            wp_die( esc_html__( 'You do not have permission to edit the policy fiches.', 'presshub-ai-editor' ), 403 );
        }
        check_admin_referer( self::NONCE );
    }

    private static function back( array $args ): void {
        if ( isset( $args['fiche'] ) ) {
            $args['fiche'] = rawurlencode( (string) $args['fiche'] );
        }
        wp_safe_redirect( self::page_url( $args ) );
        exit;
    }

    private static function posted(): array {
        return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
    }

    /** "text | url" lines → facts. */
    public static function parse_facts_text( string $text ): array {
        $out = [];
        foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $line ) {
            $parts = array_map( 'trim', explode( '|', $line ) );
            if ( '' !== ( $parts[0] ?? '' ) ) {
                $out[] = [ 'text' => $parts[0], 'url' => $parts[1] ?? '' ];
            }
        }
        return $out;
    }

    public static function facts_text( array $facts ): string {
        return implode( "\n", array_map( static function ( $x ) {
            return $x['text'] . ( '' !== $x['url'] ? ' | ' . $x['url'] : '' );
        }, $facts ) );
    }

    private static function csv( string $s ): array {
        return array_values( array_filter( array_map( 'trim', explode( ',', $s ) ), 'strlen' ) );
    }

    public static function handle_create(): void {
        self::guard();
        $post = self::posted();
        $res  = self::create( sanitize_text_field( (string) ( $post['title'] ?? '' ) ), [ 'origin' => 'edited' ], (int) get_current_user_id() );
        is_wp_error( $res ) ? self::back( [ 'notice' => $res->get_error_code() ] ) : self::back( [ 'fiche' => $res, 'notice' => 'created' ] );
    }

    public static function handle_save(): void {
        self::guard();
        $post = self::posted();
        $slug = sanitize_text_field( (string) ( $post['fiche'] ?? '' ) );
        $res  = self::save_core( $slug, [
            'title'      => sanitize_text_field( (string) ( $post['title'] ?? '' ) ),
            'aliases'    => self::csv( sanitize_text_field( (string) ( $post['aliases'] ?? '' ) ) ),
            'principles' => sanitize_textarea_field( (string) ( $post['principles'] ?? '' ) ),
            'framing'    => sanitize_textarea_field( (string) ( $post['framing'] ?? '' ) ),
            'facts'      => self::parse_facts_text( sanitize_textarea_field( (string) ( $post['facts'] ?? '' ) ) ),
            'actors'     => self::csv( sanitize_text_field( (string) ( $post['actors'] ?? '' ) ) ),
            'status'     => sanitize_key( (string) ( $post['status'] ?? 'draft' ) ),
            'locked'     => ! empty( $post['locked'] ),
        ], (int) get_current_user_id() );
        self::back( [ 'fiche' => $slug, 'notice' => is_wp_error( $res ) ? $res->get_error_code() : 'saved' ] );
    }

    public static function handle_record(): void {
        self::guard();
        $post   = self::posted();
        $slug   = sanitize_text_field( (string) ( $post['fiche'] ?? '' ) );
        $claims = [];
        foreach ( (array) ( $post['claims'] ?? [] ) as $id => $c ) {
            if ( is_array( $c ) ) {
                $claims[ sanitize_key( (string) $id ) ] = [
                    'status'  => sanitize_key( (string) ( $c['status'] ?? '' ) ),
                    'counter' => sanitize_text_field( (string) ( $c['counter'] ?? '' ) ),
                    'remove'  => ! empty( $c['remove'] ),
                ];
            }
        }
        $questions = [];
        foreach ( (array) ( $post['questions'] ?? [] ) as $id => $q ) {
            if ( is_array( $q ) ) {
                $questions[ sanitize_key( (string) $id ) ] = [ 'answered' => ! empty( $q['answered'] ), 'remove' => ! empty( $q['remove'] ) ];
            }
        }
        $new = (array) ( $post['new_claim'] ?? [] );
        $res = self::save_record( $slug, [
            'claims'       => $claims,
            'questions'    => $questions,
            'new_claim'    => [
                'claim'   => sanitize_text_field( (string) ( $new['claim'] ?? '' ) ),
                'by'      => sanitize_text_field( (string) ( $new['by'] ?? '' ) ),
                'status'  => sanitize_key( (string) ( $new['status'] ?? 'unchecked' ) ),
                'counter' => sanitize_text_field( (string) ( $new['counter'] ?? '' ) ),
            ],
            'new_question' => sanitize_text_field( (string) ( $post['new_question'] ?? '' ) ),
        ], (int) get_current_user_id() );
        self::back( [ 'fiche' => $slug, 'notice' => is_wp_error( $res ) ? $res->get_error_code() : 'record_saved' ] );
    }

    public static function handle_suggestion(): void {
        self::guard();
        $post   = self::posted();
        $slug   = sanitize_text_field( (string) ( $post['fiche'] ?? '' ) );
        $accept = 'accept' === sanitize_key( (string) ( $post['decision'] ?? '' ) );
        $res    = self::resolve_suggestion( $slug, sanitize_key( (string) ( $post['suggestion'] ?? '' ) ), $accept, (int) get_current_user_id() );
        self::back( [ 'fiche' => $slug, 'notice' => is_wp_error( $res ) ? $res->get_error_code() : ( $accept ? 'accepted' : 'dismissed' ) ] );
    }

    public static function handle_delete(): void {
        self::guard();
        $post = self::posted();
        self::delete( sanitize_text_field( (string) ( $post['fiche'] ?? '' ) ), (int) get_current_user_id() );
        self::back( [ 'notice' => 'deleted' ] );
    }

    public static function handle_example(): void {
        self::guard();
        $res = self::create_example( (int) get_current_user_id() );
        is_wp_error( $res ) ? self::back( [ 'notice' => $res->get_error_code() ] ) : self::back( [ 'fiche' => $res, 'notice' => 'example' ] );
    }

    public static function handle_draft(): void {
        self::guard();
        self::back( [ 'notice' => self::schedule_drafts() ? 'drafting' : 'draft_failed' ] );
    }

    private static function hidden( string $action, string $slug = '' ): string {
        $nonce = function_exists( 'wp_nonce_field' ) ? wp_nonce_field( self::NONCE, '_wpnonce', true, false ) : '';
        return '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">'
            . ( '' !== $slug ? '<input type="hidden" name="fiche" value="' . esc_attr( $slug ) . '">' : '' ) . $nonce;
    }

    private static function notices(): array {
        return [
            'created'                     => [ true, __( 'Fiche created. Fill in the core and publish it to use it in prompts.', 'presshub-ai-editor' ) ],
            'saved'                       => [ true, __( 'Core saved as a new version.', 'presshub-ai-editor' ) ],
            'record_saved'                => [ true, __( 'Claims and questions saved.', 'presshub-ai-editor' ) ],
            'accepted'                    => [ true, __( 'Suggestion accepted and added to the core.', 'presshub-ai-editor' ) ],
            'dismissed'                   => [ true, __( 'Suggestion dismissed.', 'presshub-ai-editor' ) ],
            'deleted'                     => [ true, __( 'Fiche deleted.', 'presshub-ai-editor' ) ],
            'example'                     => [ true, __( 'Example fiche created as a locked draft. It is never sent to the AI: read it, then copy what helps into your own fiches or delete it.', 'presshub-ai-editor' ) ],
            'drafting'                    => [ true, __( 'Drafting started. Draft fiches appear here in a minute or two; refresh the page.', 'presshub-ai-editor' ) ],
            'draft_failed'                => [ false, __( 'Could not start drafting (WP-Cron is not available).', 'presshub-ai-editor' ) ],
            'policy_title_required'       => [ false, __( 'A policy fiche needs a title.', 'presshub-ai-editor' ) ],
            'policy_exists'               => [ false, __( 'A fiche for this policy file already exists.', 'presshub-ai-editor' ) ],
            'policy_limit'                => [ false, __( 'The maximum number of policy fiches is reached.', 'presshub-ai-editor' ) ],
            'policy_not_found'            => [ false, __( 'Policy fiche not found.', 'presshub-ai-editor' ) ],
            'policy_suggestion_not_found' => [ false, __( 'Suggestion not found.', 'presshub-ai-editor' ) ],
        ];
    }

    private static function status_label( string $status ): string {
        $labels = [
            'unchecked' => __( 'Unchecked', 'presshub-ai-editor' ),
            'contested' => __( 'Contested', 'presshub-ai-editor' ),
            'confirmed' => __( 'Confirmed', 'presshub-ai-editor' ),
            'false'     => __( 'False', 'presshub-ai-editor' ),
            'draft'     => __( 'Draft', 'presshub-ai-editor' ),
            'published' => __( 'Published', 'presshub-ai-editor' ),
        ];
        return $labels[ $status ] ?? $status;
    }

    // ---------------------------------------------------------------------
    // Writing guide, example and warnings
    // ---------------------------------------------------------------------

    /** Short examples shown inside the empty core fields. */
    const PLACEHOLDERS = [
        'aliases'    => 'συντάξεις, συνταξιούχοι, αύξηση συντάξεων, Ταμείο Κοινωνικών Ασφαλίσεων',
        'principles' => "Η κάλυψη ρωτά πάντα: ποιος αποφασίζει και σε ποιο στάδιο βρίσκεται η απόφαση· πόσο κοστίζει και ποιος πληρώνει· ποιοι ωφελούνται και ποιοι όχι.",
        'framing'    => "Οι κατηγορίες της κυβέρνησης ότι η Βουλή μπλοκάρει την αύξηση αποδίδονται («σύμφωνα με την κυβέρνηση»), δεν γράφονται ως γεγονός.\nΤο «έως 100 ευρώ» γράφεται πάντα με το «έως» και με το ποιους αφορά.",
        'facts'      => 'Η πρόταση αφορά συνταξιούχους κάτω από το όριο φτώχειας | https://… (σύνδεσμος στην πηγή)',
        'actors'     => 'Υπουργείο Εργασίας, Βουλή, ΠΕΟ, ΣΕΚ, Ομοσπονδία Εργοδοτών',
    ];

    /**
     * The worked example (pension reform). Illustrative only: the facts and the
     * claim are placeholders in brackets for the editor to replace.
     */
    public static function example_fiche(): array {
        return [
            'aliases'    => [ 'συντάξεις', 'συνταξιούχοι', 'αύξηση συντάξεων', 'χαμηλοσυνταξιούχοι', 'Ταμείο Κοινωνικών Ασφαλίσεων', 'pension reform' ],
            'principles' => "Η κάλυψη ρωτά πάντα: ποιος αποφασίζει (Υπουργικό, Βουλή, κοινωνικοί εταίροι) και σε ποιο στάδιο βρίσκεται η απόφαση· πόσο κοστίζει και ποιος πληρώνει· ποιοι συνταξιούχοι ωφελούνται και ποιοι όχι.\n"
                . 'Η αύξηση των σημερινών χαμηλών συντάξεων και οι αλλαγές στις παροχές του σημερινού εργατικού δυναμικού είναι διαφορετικά ζητήματα. Όταν παρουσιάζονται ως ένα πακέτο, το επισημαίνουμε.',
            'framing'    => "Οι κατηγορίες της κυβέρνησης ότι η Βουλή ή οι κοινωνικοί εταίροι εμποδίζουν την αύξηση αποδίδονται («σύμφωνα με την κυβέρνηση») και δεν γράφονται ως γεγονός· δίνεται η απάντηση της άλλης πλευράς αν υπάρχει.\n"
                . "Το «έως 100 ευρώ» γράφεται πάντα με το «έως» και με το ποιους αφορά.\n"
                . 'Δεν χρησιμοποιούμε στη δική μας φωνή τις λέξεις «μπλόκο» και «εμπόδια».',
            'facts'      => [
                [ 'text' => 'Η πρόταση για αύξηση έως 100 ευρώ αφορά συνταξιούχους με εισόδημα κάτω από [όριο] (αντικαταστήστε και βάλτε σύνδεσμο)', 'url' => '' ],
                [ 'text' => 'Μέχρι [ημερομηνία] δεν είχε κατατεθεί νομοσχέδιο στη Βουλή (αντικαταστήστε και βάλτε σύνδεσμο)', 'url' => '' ],
            ],
            'claims'     => [
                [ 'claim' => 'Η Βουλή και οι κοινωνικοί εταίροι εμποδίζουν την αύξηση των συντάξεων', 'by' => 'Κυβέρνηση', 'status' => 'contested', 'counter' => '[Η απάντηση της Βουλής ή των συντεχνιών, π.χ. ότι δεν έχει κατατεθεί νομοσχέδιο]', 'origin' => 'editor' ],
            ],
            'questions'  => [ 'Ποιο είναι το ετήσιο κόστος της αύξησης και από πού χρηματοδοτείται;', 'Τι αλλάζει για όσους εργάζονται σήμερα και πώς συνδέεται με την αύξηση;' ],
            'actors'     => [ 'Υπουργείο Εργασίας', 'Βουλή', 'ΠΕΟ', 'ΣΕΚ', 'Ομοσπονδία Εργοδοτών' ],
        ];
    }

    /**
     * Create the example as a locked draft (never injected, never updated).
     * When a real fiche already uses its aliases, it is made without them.
     *
     * @return string|WP_Error Slug.
     */
    public static function create_example( int $user_id = 0 ) {
        $title = 'Παράδειγμα: συνταξιοδοτική μεταρρύθμιση';
        $slug  = self::slug( $title );
        if ( null !== self::get( $slug ) ) {
            return $slug;
        }
        $data = array_merge( self::example_fiche(), [ 'status' => 'draft', 'locked' => true, 'origin' => 'edited' ] );
        $res  = self::create( $title, $data, $user_id );
        if ( is_wp_error( $res ) && 'policy_exists' === $res->get_error_code() ) {
            $data['aliases'] = [];
            $res             = self::create( $title, $data, $user_id );
        }
        return $res;
    }

    /** The "how to write a fiche" panel (open when $open). */
    public static function guide_html( bool $open = false ): string {
        $li = static function ( array $items ): string {
            return '<ul style="margin: 4px 0 8px 20px; list-style: disc;"><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul>';
        };
        $ex   = self::example_fiche();
        $pre  = static function ( string $label, string $text ): string {
            return '<p style="margin: 8px 0 2px;"><strong>' . esc_html( $label ) . '</strong></p><pre class="presshub-memory-preview-text" style="white-space: pre-wrap;">' . esc_html( $text ) . '</pre>';
        };
        $html  = '<details class="presshub-policy-guide"' . ( $open ? ' open' : '' ) . ' style="margin: 12px 0; padding: 8px 12px; background: #fff; border: 1px solid #dcdcde;"><summary><strong>' . esc_html__( 'How to write a policy fiche (with an example)', 'presshub-ai-editor' ) . '</strong></summary>';
        $html .= '<p>' . esc_html__( 'A published fiche goes to the AI as instructions, ahead of the articles. The articles still decide what happened; the fiche decides how it is told: what must be attributed, which questions to ask, which words to use. Write it in Greek, like the prompts, in short sentences the AI can follow in one reading.', 'presshub-ai-editor' ) . '</p>';
        $html .= '<h4 style="margin: 10px 0 4px;">' . esc_html__( 'What each field is for', 'presshub-ai-editor' ) . '</h4>' . $li( [
            __( 'Aliases: the words the news actually uses for this file, in Greek (and English if used). The fiche is used only when one of them, or the title, appears in the material. Word endings are tolerated (συντάξεις matches συντάξεων).', 'presshub-ai-editor' ),
            __( 'Principles: the questions coverage must always ask and the newsroom\'s stance, in neutral terms. They should still hold in a month.', 'presshub-ai-editor' ),
            __( 'Framing rules: concrete wording rules, one per line: what is always attributed, words to use or avoid, what must always come with what.', 'presshub-ai-editor' ),
            __( 'Confirmed facts: only what you have checked, one per line as "text | link". The AI may use them as background.', 'presshub-ai-editor' ),
            __( 'Claims register (below the core): what someone says that is disputed or unchecked, who says it and the counter-point. Mark a claim False only when you can show it is false.', 'presshub-ai-editor' ),
            __( 'Open questions: what coverage has not answered yet. The AI may raise them, and the system marks them answered when a brief answers them.', 'presshub-ai-editor' ),
        ] );
        $html .= '<h4 style="margin: 10px 0 4px;">' . esc_html__( 'Do', 'presshub-ai-editor' ) . '</h4>' . $li( [
            __( 'Put the most important rule first: when there is little room (podcast, social copy) the AI gets the principles and framing first, and social copy gets the framing only.', 'presshub-ai-editor' ),
            __( 'Ask for attribution and the other side, not for a conclusion: «αποδίδεται στην κυβέρνηση και δίνεται η απάντηση της αντιπολίτευσης».', 'presshub-ai-editor' ),
            __( 'Give numbers with their limits: «έως 100 ευρώ, για όσους…», «σύμφωνα με το υπουργείο».', 'presshub-ai-editor' ),
            __( 'Use phrases for acronyms: «Ταμείο Κοινωνικών Ασφαλίσεων» rather than «ΤΚΑ»; one word under 6 letters never matches on its own.', 'presshub-ai-editor' ),
            __( 'Keep it a draft until it is checked, and lock it when nothing should be added automatically.', 'presshub-ai-editor' ),
        ] );
        $html .= '<h4 style="margin: 10px 0 4px;">' . esc_html__( 'Do not', 'presshub-ai-editor' ) . '</h4>' . $li( [
            __( 'Do not write today\'s news in the core (dates, the latest statement): it goes stale and the AI may present it as new. It belongs in the timeline and the claims register.', 'presshub-ai-editor' ),
            __( 'Do not ask the AI to take a side, praise or attack anyone, or leave facts out («πάντα να επικρίνεις την κυβέρνηση»). That makes the briefing biased and less credible; ask for attribution and balance instead.', 'presshub-ai-editor' ),
            __( 'Do not put format or length instructions here («γράψε 300 λέξεις», «βάλε τίτλο»). Each call has its own; they would conflict.', 'presshub-ai-editor' ),
            __( 'Do not put unverified numbers in the facts. Record them as a claim with who said them.', 'presshub-ai-editor' ),
            __( 'Do not paste whole articles, press releases or text addressed to the AI («αγνόησε τις άλλες οδηγίες»).', 'presshub-ai-editor' ),
        ] );
        $html .= '<h4 style="margin: 10px 0 4px;">' . esc_html__( 'Example: pension reform (the parts in brackets are placeholders)', 'presshub-ai-editor' ) . '</h4>';
        $html .= $pre( __( 'Aliases', 'presshub-ai-editor' ), implode( ', ', $ex['aliases'] ) );
        $html .= $pre( __( 'Principles', 'presshub-ai-editor' ), $ex['principles'] );
        $html .= $pre( __( 'Framing rules', 'presshub-ai-editor' ), $ex['framing'] );
        $html .= $pre( __( 'Confirmed facts', 'presshub-ai-editor' ), self::facts_text( $ex['facts'] ) );
        $c     = $ex['claims'][0];
        $html .= $pre( __( 'A claim in the register', 'presshub-ai-editor' ), '«' . $c['claim'] . '» — ' . $c['by'] . "\n" . __( 'Status', 'presshub-ai-editor' ) . ': ' . self::status_label( $c['status'] ) . "\n" . __( 'Counter-point', 'presshub-ai-editor' ) . ': ' . $c['counter'] );
        $html .= $pre( __( 'Open questions', 'presshub-ai-editor' ), implode( "\n", $ex['questions'] ) );
        $html .= $pre( __( 'Not like this', 'presshub-ai-editor' ), "✗ Η κυβέρνηση λέει ψέματα για τις συντάξεις.\n✗ Χθες ο υπουργός δήλωσε ότι η Βουλή φταίει.\n✗ Γράψε σύντομα και με τίτλο.\n✗ Η αύξηση κοστίζει 40 εκατ. (χωρίς πηγή)" );
        $html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top: 8px;">' . self::hidden( self::POST_EXAMPLE ) . '<button class="button">' . esc_html__( 'Create this example as a locked draft', 'presshub-ai-editor' ) . '</button> <span class="description">' . esc_html__( 'It is never sent to the AI.', 'presshub-ai-editor' ) . '</span></form>';
        return $html . '</details>';
    }

    /**
     * Common mistakes in a fiche, as plain sentences ([] when none).
     */
    public static function core_warnings( array $f ): array {
        $out   = [];
        $short = [];
        foreach ( array_merge( [ $f['title'] ], $f['aliases'] ) as $n ) {
            if ( '' === self::topic_pattern( (string) $n ) && '' !== trim( (string) $n ) ) {
                $short[] = (string) $n;
            }
        }
        if ( empty( self::patterns( $f ) ) ) {
            $out[] = __( 'Neither the title nor any alias can match the news, so this fiche is never used. Add aliases of two words or more, or single words of 6 letters or more.', 'presshub-ai-editor' );
        } elseif ( ! empty( $short ) ) {
            /* translators: %s: list of aliases */
            $out[] = sprintf( __( 'These never match on their own (one word under 6 letters): %s. Use a longer phrase, e.g. the full name.', 'presshub-ai-editor' ), implode( ', ', $short ) );
        }
        if ( 'published' === $f['status'] && '' === trim( $f['principles'] . $f['framing'] ) ) {
            $out[] = __( 'Published without principles or framing rules: the AI only gets the facts and claims.', 'presshub-ai-editor' );
        }
        $no_link = count( array_filter( $f['facts'], static function ( $x ) { return '' === $x['url']; } ) );
        if ( $no_link > 0 ) {
            /* translators: %d: number of facts */
            $out[] = sprintf( _n( '%d confirmed fact has no link to its source.', '%d confirmed facts have no link to their source.', $no_link, 'presshub-ai-editor' ), $no_link );
        }
        if ( preg_match( '/\b(?:\d{1,2}\/\d{1,2}(?:\/\d{2,4})?|20\d\d-\d\d-\d\d)\b|(?<!\p{L})(?:χθες|σήμερα|αύριο)(?!\p{L})/iu', $f['principles'] . ' ' . $f['framing'] ) ) {
            $out[] = __( 'The principles or framing mention a date or "today/yesterday". News of the day belongs in the timeline and claims; the core should hold for weeks.', 'presshub-ai-editor' );
        }
        if ( preg_match( '/αγνόησε|ignore (?:all |the )?(?:previous|other) instructions/iu', $f['principles'] . ' ' . $f['framing'] ) ) {
            $out[] = __( 'The core tells the AI to ignore other instructions. Remove that: it can break the call\'s own rules.', 'presshub-ai-editor' );
        }
        foreach ( [ 'podcast_script' => __( 'podcast', 'presshub-ai-editor' ), 'social_copy_' => __( 'social copy', 'presshub-ai-editor' ) ] as $action => $label ) {
            $hit = [ $f['slug'] => [ 'count' => 1, 'fiche' => $f ] ];
            if ( '' !== trim( $f['principles'] . $f['framing'] ) && '' === self::render( $hit, $action, self::budget_for( $action ) )['text'] ) {
                /* translators: 1: call name, 2: character budget */
                $out[] = sprintf( __( 'Too long for the %1$s calls (room for %2$d characters): they skip this fiche. Shorten the principles and framing.', 'presshub-ai-editor' ), $label, self::budget_for( $action ) );
            }
        }
        return $out;
    }

    public static function build_page( array $query = [] ): string {
        $html   = '';
        $notice = isset( $query['notice'] ) ? sanitize_key( (string) $query['notice'] ) : '';
        $n      = self::notices();
        if ( isset( $n[ $notice ] ) ) {
            $html .= '<div class="notice ' . ( $n[ $notice ][0] ? 'notice-success' : 'notice-error' ) . ' inline"><p>' . esc_html( $n[ $notice ][1] ) . '</p></div>';
        }
        $fiches = self::all();
        $open   = isset( $query['fiche'] ) ? sanitize_text_field( rawurldecode( (string) $query['fiche'] ) ) : '';
        if ( '' !== $open && isset( $fiches[ $open ] ) ) {
            return $html . self::edit_page( $fiches[ $open ] );
        }
        return $html . self::list_page( $fiches );
    }

    private static function list_page( array $fiches ): string {
        $url   = esc_url( admin_url( 'admin-post.php' ) );
        $html  = '<p class="description">' . esc_html__( 'One fiche per policy file. The core (principles, framing rules, confirmed facts) is yours and is never rewritten automatically. After each published brief the system adds dated timeline items, claims and open questions, and suggests core changes for you to accept. Published fiches are added to the AI calls whose material mentions their title or aliases.', 'presshub-ai-editor' ) . '</p>';
        $state = get_option( self::DRAFT_STATE, [] );
        if ( is_array( $state ) && in_array( $state['status'] ?? '', [ 'queued', 'running' ], true ) ) {
            $html .= '<div class="notice notice-info inline"><p>' . esc_html__( 'Drafting fiches from the agenda and memory is in progress.', 'presshub-ai-editor' ) . '</p></div>';
        } elseif ( is_array( $state ) && 'error' === ( $state['status'] ?? '' ) ) {
            $html .= '<div class="notice notice-error inline"><p>' . esc_html( sprintf( /* translators: %s: error */ __( 'The last drafting run failed: %s', 'presshub-ai-editor' ), (string) ( $state['message'] ?? '' ) ) ) . '</p></div>';
        }
        $html .= '<div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin: 12px 0;">';
        $html .= '<form method="post" action="' . $url . '">' . self::hidden( self::POST_CREATE ) . '<label>' . esc_html__( 'New fiche title', 'presshub-ai-editor' ) . ' <input type="text" name="title" class="regular-text" required></label> <button class="button button-primary">' . esc_html__( 'Create fiche', 'presshub-ai-editor' ) . '</button></form>';
        $html .= '<form method="post" action="' . $url . '">' . self::hidden( self::POST_DRAFT ) . '<button class="button">' . esc_html__( 'Draft fiches from the agenda and memory', 'presshub-ai-editor' ) . '</button></form>';
        $html .= '</div>';
        $html .= self::guide_html( empty( $fiches ) );
        if ( empty( $fiches ) ) {
            return $html . '<p>' . esc_html__( 'No policy fiches yet.', 'presshub-ai-editor' ) . '</p>';
        }
        uasort( $fiches, static function ( $a, $b ) {
            return [ 'published' === $b['status'], $b['last_seen'] ] <=> [ 'published' === $a['status'], $a['last_seen'] ];
        } );
        $html .= '<table class="widefat striped presshub-policy-fiches"><thead><tr><th>' . esc_html__( 'Policy file', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Status', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Claims (contested / unchecked)', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Open questions', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Suggestions', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Last seen', 'presshub-ai-editor' ) . '</th></tr></thead><tbody>';
        foreach ( $fiches as $slug => $f ) {
            $count = static function ( array $items, string $key, string $value ): int {
                return count( array_filter( $items, static function ( $x ) use ( $key, $value ) { return $value === $x[ $key ]; } ) );
            };
            $status = self::status_label( $f['status'] ) . ( $f['locked'] ? ' · ' . __( 'locked', 'presshub-ai-editor' ) : '' );
            $html  .= '<tr><td><a href="' . esc_url( self::edit_url( (string) $slug ) ) . '"><strong>' . esc_html( $f['title'] ) . '</strong></a>'
                . ( ! empty( $f['aliases'] ) ? '<br><span class="description">' . esc_html( implode( ', ', array_slice( $f['aliases'], 0, 5 ) ) ) . '</span>' : '' ) . '</td>'
                . '<td>' . esc_html( $status ) . '</td>'
                . '<td>' . (int) $count( $f['claims'], 'status', 'contested' ) . ' / ' . (int) $count( $f['claims'], 'status', 'unchecked' ) . '</td>'
                . '<td>' . (int) $count( $f['questions'], 'status', 'open' ) . '</td>'
                . '<td>' . count( $f['suggestions'] ) . '</td>'
                . '<td>' . esc_html( $f['last_seen'] ) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private static function edit_page( array $f ): string {
        $url  = esc_url( admin_url( 'admin-post.php' ) );
        $slug = $f['slug'];
        $row  = static function ( string $label, string $field ): string {
            return '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field . '</td></tr>';
        };
        $html  = '<p><a href="' . esc_url( self::page_url() ) . '">&larr; ' . esc_html__( 'All policy fiches', 'presshub-ai-editor' ) . '</a></p>';
        $html .= '<h2>' . esc_html( $f['title'] ) . ' <span class="description">(' . esc_html( self::status_label( $f['status'] ) ) . ', v' . (int) $f['version'] . ')</span></h2>';
        if ( 'draft' === $f['status'] ) {
            $html .= '<div class="notice notice-warning inline"><p>' . esc_html__( 'Draft: not used in prompts. Check the core and set it to Published.', 'presshub-ai-editor' ) . '</p></div>';
        }

        $warnings = self::core_warnings( $f );
        if ( ! empty( $warnings ) ) {
            $html .= '<div class="notice notice-warning inline presshub-policy-warnings"><p><strong>' . esc_html__( 'Check before publishing:', 'presshub-ai-editor' ) . '</strong></p><ul style="margin: 0 0 8px 20px; list-style: disc;"><li>' . implode( '</li><li>', array_map( 'esc_html', $warnings ) ) . '</li></ul></div>';
        }
        $html .= self::guide_html( '' === trim( $f['principles'] . $f['framing'] ) );

        // Suggestions first: they wait for a decision.
        if ( ! empty( $f['suggestions'] ) ) {
            $html .= '<h3>' . esc_html__( 'Suggested changes to the core', 'presshub-ai-editor' ) . '</h3><table class="widefat striped"><tbody>';
            foreach ( $f['suggestions'] as $s ) {
                $form  = static function ( string $decision, string $label, string $class ) use ( $url, $slug, $s ): string {
                    return '<form method="post" action="' . $url . '" style="display:inline">' . self::hidden( self::POST_SUGGESTION, $slug )
                        . '<input type="hidden" name="suggestion" value="' . esc_attr( $s['id'] ) . '"><input type="hidden" name="decision" value="' . esc_attr( $decision ) . '"><button class="button ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
                };
                $html .= '<tr><td><strong>' . esc_html( ucfirst( $s['field'] ) ) . '</strong> · ' . esc_html( $s['date'] ) . '<br>' . esc_html( $s['text'] )
                    . ( '' !== $s['reason'] ? '<br><span class="description">' . esc_html( $s['reason'] ) . '</span>' : '' ) . '</td><td style="white-space:nowrap">'
                    . $form( 'accept', __( 'Accept', 'presshub-ai-editor' ), 'button-primary' ) . ' ' . $form( 'dismiss', __( 'Dismiss', 'presshub-ai-editor' ), '' ) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        // Core.
        $html .= '<h3>' . esc_html__( 'Core (editor-owned)', 'presshub-ai-editor' ) . '</h3><form method="post" action="' . $url . '">' . self::hidden( self::POST_SAVE, $slug ) . '<table class="form-table"><tbody>';
        $html .= $row( __( 'Title', 'presshub-ai-editor' ), '<input type="text" class="regular-text" name="title" value="' . esc_attr( $f['title'] ) . '" required>' );
        $html .= $row( __( 'Aliases (comma separated, used for matching)', 'presshub-ai-editor' ), '<input type="text" class="large-text" name="aliases" value="' . esc_attr( implode( ', ', $f['aliases'] ) ) . '" placeholder="' . esc_attr( self::PLACEHOLDERS['aliases'] ) . '">' );
        $html .= $row( __( 'Principles', 'presshub-ai-editor' ), '<textarea name="principles" rows="4" class="large-text" placeholder="' . esc_attr( self::PLACEHOLDERS['principles'] ) . '">' . esc_textarea( $f['principles'] ) . '</textarea><p class="description">' . esc_html__( 'The questions coverage always asks and the newsroom\'s stance, in neutral terms. Most important first. No news of the day.', 'presshub-ai-editor' ) . '</p>' );
        $html .= $row( __( 'Framing rules', 'presshub-ai-editor' ), '<textarea name="framing" rows="4" class="large-text" placeholder="' . esc_attr( self::PLACEHOLDERS['framing'] ) . '">' . esc_textarea( $f['framing'] ) . '</textarea><p class="description">' . esc_html__( 'One rule per line: what is always attributed, words to use or avoid, what must always come with what. Social copy gets only these.', 'presshub-ai-editor' ) . '</p>' );
        $html .= $row( __( 'Confirmed facts (text | link, one per line)', 'presshub-ai-editor' ), '<textarea name="facts" rows="5" class="large-text code" placeholder="' . esc_attr( self::PLACEHOLDERS['facts'] ) . '">' . esc_textarea( self::facts_text( $f['facts'] ) ) . '</textarea>' );
        $html .= $row( __( 'Actors (comma separated)', 'presshub-ai-editor' ), '<input type="text" class="large-text" name="actors" value="' . esc_attr( implode( ', ', $f['actors'] ) ) . '" placeholder="' . esc_attr( self::PLACEHOLDERS['actors'] ) . '">' );
        $status_opts = '';
        foreach ( self::STATUSES as $st ) {
            $status_opts .= '<option value="' . esc_attr( $st ) . '"' . ( $st === $f['status'] ? ' selected' : '' ) . '>' . esc_html( self::status_label( $st ) ) . '</option>';
        }
        $html .= $row( __( 'Status', 'presshub-ai-editor' ), '<select name="status">' . $status_opts . '</select> <label><input type="checkbox" name="locked" value="1"' . ( $f['locked'] ? ' checked' : '' ) . '> ' . esc_html__( 'Locked (nothing is added automatically)', 'presshub-ai-editor' ) . '</label>' );
        $html .= '</tbody></table><p><button type="submit" class="button button-primary">' . esc_html__( 'Save core', 'presshub-ai-editor' ) . '</button></p></form>';

        // Claims and questions.
        $html .= '<h3>' . esc_html__( 'Claims register', 'presshub-ai-editor' ) . '</h3><form method="post" action="' . $url . '">' . self::hidden( self::POST_RECORD, $slug );
        $html .= '<table class="widefat striped presshub-policy-claims"><thead><tr><th>' . esc_html__( 'Claim', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Status', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Counter-point', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Remove', 'presshub-ai-editor' ) . '</th></tr></thead><tbody>';
        $select = static function ( string $name, string $current ): string {
            $o = '';
            foreach ( self::CLAIM_STATUSES as $st ) {
                $o .= '<option value="' . esc_attr( $st ) . '"' . ( $st === $current ? ' selected' : '' ) . '>' . esc_html( self::status_label( $st ) ) . '</option>';
            }
            return '<select name="' . esc_attr( $name ) . '">' . $o . '</select>';
        };
        foreach ( array_reverse( $f['claims'] ) as $c ) {
            $id    = $c['id'];
            $meta  = trim( $c['by'] . ( '' !== $c['date'] ? ' · ' . $c['date'] : '' ) . ( 'auto' === $c['origin'] ? ' · ' . __( 'added automatically', 'presshub-ai-editor' ) : '' ) );
            $html .= '<tr><td>«' . esc_html( $c['claim'] ) . '»' . ( '' !== $meta ? '<br><span class="description">' . esc_html( $meta ) . '</span>' : '' )
                . ( '' !== $c['url'] ? ' <a href="' . esc_url( $c['url'] ) . '">' . esc_html__( 'source', 'presshub-ai-editor' ) . '</a>' : '' )
                . ( '' !== $c['reason'] ? '<br><span class="description">' . esc_html( $c['reason'] ) . '</span>' : '' ) . '</td>'
                . '<td>' . $select( 'claims[' . $id . '][status]', $c['status'] ) . '</td>'
                . '<td><input type="text" class="regular-text" name="claims[' . esc_attr( $id ) . '][counter]" value="' . esc_attr( $c['counter'] ) . '"></td>'
                . '<td><input type="checkbox" name="claims[' . esc_attr( $id ) . '][remove]" value="1"></td></tr>';
        }
        $html .= '<tr><td><input type="text" class="large-text" name="new_claim[claim]" placeholder="' . esc_attr__( 'Add a claim', 'presshub-ai-editor' ) . '"><input type="text" class="regular-text" name="new_claim[by]" placeholder="' . esc_attr__( 'Made by', 'presshub-ai-editor' ) . '"></td><td>' . $select( 'new_claim[status]', 'unchecked' ) . '</td><td><input type="text" class="regular-text" name="new_claim[counter]"></td><td></td></tr>';
        $html .= '</tbody></table>';

        $html .= '<h3>' . esc_html__( 'Open questions', 'presshub-ai-editor' ) . '</h3><ul>';
        foreach ( $f['questions'] as $q ) {
            $html .= '<li><label><input type="checkbox" name="questions[' . esc_attr( $q['id'] ) . '][answered]" value="1"' . ( 'answered' === $q['status'] ? ' checked' : '' ) . '> ' . esc_html( $q['text'] ) . '</label>'
                . ( '' !== $q['answer'] ? ' <span class="description">— ' . esc_html( $q['answer'] ) . '</span>' : '' )
                . ' <label class="description"><input type="checkbox" name="questions[' . esc_attr( $q['id'] ) . '][remove]" value="1"> ' . esc_html__( 'remove', 'presshub-ai-editor' ) . '</label></li>';
        }
        $html .= '</ul><p><input type="text" class="large-text" name="new_question" placeholder="' . esc_attr__( 'Add an open question', 'presshub-ai-editor' ) . '"></p>';
        $html .= '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save claims and questions', 'presshub-ai-editor' ) . '</button></p></form>';

        // Timeline (read-only).
        $html .= '<h3>' . esc_html__( 'Timeline', 'presshub-ai-editor' ) . '</h3>';
        if ( empty( $f['timeline'] ) ) {
            $html .= '<p>' . esc_html__( 'Nothing yet. Items are added after each published brief that mentions this file.', 'presshub-ai-editor' ) . '</p>';
        } else {
            $html .= '<ul>';
            foreach ( $f['timeline'] as $t ) {
                $html .= '<li>' . esc_html( ( '' !== $t['date'] ? '[' . $t['date'] . '] ' : '' ) . $t['text'] ) . ( '' !== $t['url'] ? ' <a href="' . esc_url( $t['url'] ) . '">' . esc_html__( 'brief', 'presshub-ai-editor' ) . '</a>' : '' ) . '</li>';
            }
            $html .= '</ul>';
        }

        // Preview and history.
        $preview = self::render( [ $slug => [ 'count' => 1, 'fiche' => $f ] ], 'briefing_curation', self::budget_for( 'briefing_curation' ) );
        $html   .= '<details><summary>' . esc_html__( 'Show the exact text the morning curation receives', 'presshub-ai-editor' ) . '</summary>'
            . ( '' === $preview['text'] ? '<p>' . esc_html__( 'Nothing would be added.', 'presshub-ai-editor' ) . '</p>' : '<pre class="presshub-memory-preview-text">' . esc_html( $preview['text'] ) . '</pre>' ) . '</details>';
        if ( ! empty( $f['history'] ) ) {
            $html .= '<h3>' . esc_html__( 'Core history', 'presshub-ai-editor' ) . '</h3>';
            foreach ( $f['history'] as $h ) {
                $html .= '<details><summary>' . esc_html( 'v' . (int) ( $h['version'] ?? 0 ) . ' · ' . (string) ( $h['at'] ?? '' ) ) . '</summary><pre>' . esc_html(
                    'Αρχές: ' . (string) ( $h['principles'] ?? '' ) . "\nΠλαισίωση: " . (string) ( $h['framing'] ?? '' ) . "\nΣτοιχεία:\n" . self::facts_text( self::normalize( [ 'title' => 'x', 'facts' => (array) ( $h['facts'] ?? [] ) ] )['facts'] )
                ) . '</pre></details>';
            }
        }

        $html .= '<form method="post" action="' . $url . '" style="margin-top: 24px;" onsubmit="return confirm(\'' . esc_js( __( 'Delete this policy fiche?', 'presshub-ai-editor' ) ) . '\');">' . self::hidden( self::POST_DELETE, $slug ) . '<button class="button button-link-delete">' . esc_html__( 'Delete fiche', 'presshub-ai-editor' ) . '</button></form>';
        return $html;
    }
}
