<?php
/**
 * PressHub_AI_Stakeholders — Issue #197: fiches for the people in the news.
 *
 *  - Fiche: name (Greek), English name, aliases, role, party, a bio line, a
 *    dated list of stated positions (topic, position, source link), first and
 *    last seen, and how many editions mentioned the person.
 *  - Build: after a daily digest is built, one structured AI call extracts the
 *    people and their stated positions from the published brief (async cron),
 *    and they are merged into the fiches by name or alias.
 *  - Use: for the curation and podcast-script calls, the fiches of people who
 *    appear in that call's material are added to the system prompt within a
 *    budget, so titles stay right and changes of position can be noted.
 *  - Editor: Memory → Stakeholders tab to edit, lock, exclude and merge.
 *    Edited fiches keep their name, role, party and bio on rebuild (new
 *    positions and sightings are still added); locked fiches are never changed
 *    automatically; excluded fiches are never injected.
 *
 * Storage is one non-autoloaded option keyed by slug (capped at MAX_FICHES;
 * the least recently seen unedited fiches are dropped first).
 *
 * @package PressHub_AI_Editor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Stakeholders {

    const OPTION            = 'presshub_ai_stakeholders';
    const CRON_HOOK         = 'presshub_ai_stakeholders_extract';
    const MAX_FICHES        = 250;
    const MAX_POSITIONS     = 10;
    const MAX_ALIASES       = 8;
    const MAX_PEOPLE_PER_RUN = 15;
    const NONCE             = 'presshub_ai_stakeholders';
    const POST_SAVE         = 'presshub_ai_stakeholder_save';
    const POST_MERGE        = 'presshub_ai_stakeholder_merge';

    /** Character budget per action for the injected block. */
    const BUDGETS = [
        'briefing_curation' => 2500,
        'podcast_script'    => 1500,
    ];
    const MAX_INJECTED          = 12;
    const POSITIONS_PER_INJECT  = 3;

    private static ?array $last_applied = null;
    private static ?PressHub_AI_News_Harvester $folder = null;

    // ---------------------------------------------------------------------
    // Hooks
    // ---------------------------------------------------------------------

    public static function register_hooks(): void {
        add_action( 'presshub_ai_memory_digest_built', [ __CLASS__, 'schedule_extraction' ], 10, 1 );
        add_action( self::CRON_HOOK, [ __CLASS__, 'handle_cron' ], 10, 1 );
        add_action( 'admin_post_' . self::POST_SAVE, [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_post_' . self::POST_MERGE, [ __CLASS__, 'handle_merge' ] );
    }

    public static function is_enabled(): bool {
        // #233: the Editorial knowledge switch (default on) sits under Editorial memory.
        $on = class_exists( 'PressHub_AI_Settings_Storage' ) ? ( PressHub_AI_Settings_Storage::get_memory_enabled() && PressHub_AI_Settings_Storage::get_stakeholders_enabled() ) : true;
        return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'presshub_ai_stakeholders_enabled', $on ) : $on;
    }

    public static function schedule_extraction( $post_id ): void {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 || ! self::is_enabled() || ! function_exists( 'wp_schedule_single_event' ) ) {
            return;
        }
        if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::CRON_HOOK, [ $post_id ] ) ) {
            return;
        }
        wp_schedule_single_event( time() + 60, self::CRON_HOOK, [ $post_id ] );
    }

    /** Post meta: hash of the brief text the people were last extracted from. */
    const META_SOURCE_HASH = '_presshub_stakeholders_source_hash';

    public static function handle_cron( $post_id ): void {
        $post_id = (int) $post_id;
        // Same guard as the policy fiche update: overlapping WP-Cron requests can
        // run one event twice, and an unchanged brief needs no second extraction.
        $post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
        // The hash covers everything the prompt is built from: the text, date and edition.
        $hash = $post ? md5( trim( wp_strip_all_tags( (string) ( $post->post_content ?? '' ) ) ) . '|' . (string) get_post_meta( $post_id, '_presshub_briefing_date', true ) . '|' . substr( (string) ( $post->post_date ?? '' ), 0, 10 ) . '|' . (string) get_post_meta( $post_id, '_presshub_briefing_edition', true ) ) : '';
        $lock = 'presshub_ai_stakeholders_lock_' . $post_id;
        if ( '' !== $hash && (string) get_post_meta( $post_id, self::META_SOURCE_HASH, true ) === $hash ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::info( 'Stakeholder extraction skipped: this brief was already processed', [ 'post_id' => $post_id ] );
            }
            return;
        }
        if ( false !== get_transient( $lock ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::info( 'Stakeholder extraction skipped: already running for this brief', [ 'post_id' => $post_id ] );
            }
            return;
        }
        set_transient( $lock, time(), 10 * MINUTE_IN_SECONDS );
        try {
            $res = self::extract_for_post( $post_id );
        } finally {
            delete_transient( $lock );
        }
        if ( is_wp_error( $res ) ) {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::warning( 'Stakeholder extraction failed', [ 'post_id' => $post_id, 'error' => $res->get_error_message() ] );
            }
        } elseif ( '' !== $hash && 'skipped' !== ( $res['status'] ?? '' ) ) {
            update_post_meta( $post_id, self::META_SOURCE_HASH, $hash );
        }
    }

    // ---------------------------------------------------------------------
    // Text helpers
    // ---------------------------------------------------------------------

    public static function fold( string $s ): string {
        $s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
        if ( null === self::$folder && class_exists( 'PressHub_AI_News_Harvester' ) ) {
            self::$folder = new PressHub_AI_News_Harvester();
        }
        if ( null !== self::$folder ) {
            $s = self::$folder->remove_accents_utf8( $s );
        }
        $s = str_replace( 'ς', 'σ', $s );
        $s = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s ) ?? $s;
        return trim( preg_replace( '/\s+/u', ' ', $s ) ?? $s );
    }

    /** Stable slug from a name (Greek kept; folded, hyphenated). */
    public static function slug( string $name ): string {
        return str_replace( ' ', '-', self::fold( $name ) );
    }

    private static function clip( string $s, int $max ): string {
        $s = trim( preg_replace( '/\s+/u', ' ', $s ) ?? $s );
        return ( function_exists( 'mb_strlen' ) && mb_strlen( $s ) > $max ) ? rtrim( mb_substr( $s, 0, $max ) ) : $s;
    }

    private static function len( string $s ): int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $s ) : strlen( $s );
    }

    private static function today(): string {
        return function_exists( 'wp_date' ) ? (string) wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
    }

    /**
     * Regex that finds a name in folded text, allowing Greek case endings
     * (Χριστοδουλίδης / Χριστοδουλίδη, Νίκος / Νίκου). '' for names too short to match safely.
     */
    public static function name_pattern( string $name ): string {
        $words = array_values( array_filter( explode( ' ', self::fold( $name ) ), 'strlen' ) );
        if ( empty( $words ) ) {
            return '';
        }
        $parts = [];
        foreach ( $words as $w ) {
            $stem = preg_replace( '/[σν]$/u', '', $w ) ?? $w;
            if ( self::len( $stem ) < 3 ) {
                $stem = $w;
            }
            $parts[] = preg_quote( $stem, '/' ) . '\p{L}{0,3}';
        }
        if ( 1 === count( $words ) && self::len( $words[0] ) < 6 ) {
            return ''; // A single short word would match too much.
        }
        return '/(?<!\p{L})' . implode( '\s+', $parts ) . '(?!\p{L})/u';
    }

    // ---------------------------------------------------------------------
    // Storage
    // ---------------------------------------------------------------------

    public static function all(): array {
        $raw = get_option( self::OPTION, [] );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $out = [];
        foreach ( $raw as $slug => $fiche ) {
            if ( is_array( $fiche ) && ! empty( $fiche['name'] ) ) {
                $out[ (string) $slug ] = self::normalize( $fiche );
            }
        }
        return $out;
    }

    public static function get( string $slug ): ?array {
        $all = self::all();
        return $all[ $slug ] ?? null;
    }

    private static function store( array $fiches ): void {
        if ( count( $fiches ) > self::MAX_FICHES ) {
            // Drop the least recently seen fiches that nobody edited or locked.
            $droppable = array_filter( $fiches, static function ( $f ) {
                return empty( $f['locked'] ) && 'edited' !== $f['origin'];
            } );
            uasort( $droppable, static function ( $a, $b ) {
                return [ $a['last_seen'], $a['mentions'] ] <=> [ $b['last_seen'], $b['mentions'] ];
            } );
            foreach ( array_keys( $droppable ) as $slug ) {
                if ( count( $fiches ) <= self::MAX_FICHES ) {
                    break;
                }
                unset( $fiches[ $slug ] );
            }
        }
        update_option( self::OPTION, $fiches, false );
    }

    public static function normalize( array $f ): array {
        $aliases = [];
        foreach ( (array) ( $f['aliases'] ?? [] ) as $a ) {
            $a = self::clip( (string) $a, 80 );
            if ( '' !== $a && ! in_array( $a, $aliases, true ) ) {
                $aliases[] = $a;
            }
        }
        $positions = [];
        foreach ( (array) ( $f['positions'] ?? [] ) as $p ) {
            if ( ! is_array( $p ) || '' === trim( (string) ( $p['position'] ?? '' ) ) ) {
                continue;
            }
            $date        = (string) ( $p['date'] ?? '' );
            $positions[] = [
                'date'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '',
                'topic'    => self::clip( (string) ( $p['topic'] ?? '' ), 60 ),
                'position' => self::clip( (string) $p['position'], 300 ),
                'url'      => esc_url_raw( (string) ( $p['url'] ?? '' ) ),
            ];
        }
        usort( $positions, static function ( $a, $b ) {
            return strcmp( $b['date'], $a['date'] );
        } );
        return [
            'name'       => self::clip( (string) ( $f['name'] ?? '' ), 80 ),
            'name_en'    => self::clip( (string) ( $f['name_en'] ?? '' ), 80 ),
            'aliases'    => array_slice( $aliases, 0, self::MAX_ALIASES ),
            'role'       => self::clip( (string) ( $f['role'] ?? '' ), 120 ),
            'party'      => self::clip( (string) ( $f['party'] ?? '' ), 80 ),
            'bio'        => self::clip( (string) ( $f['bio'] ?? '' ), 300 ),
            'positions'  => array_slice( $positions, 0, self::MAX_POSITIONS ),
            'first_seen' => (string) ( $f['first_seen'] ?? '' ),
            'last_seen'  => (string) ( $f['last_seen'] ?? '' ),
            'mentions'   => max( 0, (int) ( $f['mentions'] ?? 0 ) ),
            'seen_in'    => array_slice( array_values( array_unique( array_map( 'strval', (array) ( $f['seen_in'] ?? [] ) ) ) ), -20 ),
            'origin'     => 'edited' === ( $f['origin'] ?? '' ) ? 'edited' : 'generated',
            'locked'     => ! empty( $f['locked'] ),
            'excluded'   => ! empty( $f['excluded'] ),
            'updated_at' => (string) ( $f['updated_at'] ?? '' ),
            'updated_by' => (int) ( $f['updated_by'] ?? 0 ),
        ];
    }

    /** Slug of the fiche whose name, English name or alias matches, or ''. */
    public static function find_slug( array $fiches, string $name, array $aliases = [] ): string {
        $keys = array_filter( array_map( [ __CLASS__, 'fold' ], array_merge( [ $name ], $aliases ) ), 'strlen' );
        foreach ( $fiches as $slug => $f ) {
            $own = array_map( [ __CLASS__, 'fold' ], array_merge( [ $f['name'], $f['name_en'] ], $f['aliases'] ) );
            if ( array_intersect( $keys, array_filter( $own, 'strlen' ) ) ) {
                return (string) $slug;
            }
        }
        return '';
    }

    /**
     * Merge people extracted from one edition into the fiches.
     *
     * @param array  $people  Extracted people (name, name_en, aliases, role, party, positions).
     * @param string $date    Briefing date.
     * @param string $source  Key of the edition ("2026-11-15-morning"), counted once per fiche.
     * @param string $url     Link to the published brief.
     * @return array{created:int,updated:int,skipped:int,positions:int}
     */
    public static function merge_people( array $people, string $date, string $source, string $url = '' ): array {
        $fiches = self::all();
        $stats  = [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'positions' => 0 ];
        foreach ( array_slice( $people, 0, self::MAX_PEOPLE_PER_RUN ) as $p ) {
            if ( ! is_array( $p ) ) {
                continue;
            }
            $name = self::clip( (string) ( $p['name'] ?? '' ), 80 );
            if ( self::len( $name ) < 4 || false === strpos( $name, ' ' ) ) {
                $stats['skipped']++; // Need at least a first name and a surname.
                continue;
            }
            $aliases = array_values( array_filter( array_map( 'strval', (array) ( $p['aliases'] ?? [] ) ) ) );
            $name_en = (string) ( $p['name_en'] ?? '' );
            $slug    = self::find_slug( $fiches, $name, array_merge( $aliases, '' !== $name_en ? [ $name_en ] : [] ) );
            $is_new  = '' === $slug;
            if ( $is_new ) {
                $slug = self::slug( $name );
                if ( '' === trim( $slug, '-' ) ) {
                    $stats['skipped']++; // Punctuation-only name.
                    continue;
                }
                $fiches[ $slug ] = self::normalize( [ 'name' => $name, 'first_seen' => $date ] );
            }
            $f = $fiches[ $slug ];
            if ( $f['locked'] ) {
                $stats['skipped']++;
                continue;
            }
            if ( 'edited' !== $f['origin'] ) {
                foreach ( [ 'role', 'party', 'name_en' ] as $field ) {
                    $v = trim( (string) ( $p[ $field ] ?? '' ) );
                    if ( '' !== $v ) {
                        $f[ $field ] = $v;
                    }
                }
                $f['aliases'] = array_merge( $f['aliases'], $aliases );
            }
            foreach ( (array) ( $p['positions'] ?? [] ) as $pos ) {
                if ( ! is_array( $pos ) || '' === trim( (string) ( $pos['position'] ?? '' ) ) ) {
                    continue;
                }
                $topic = (string) ( $pos['topic'] ?? '' );
                // One position per topic per edition: replace a same-day duplicate.
                $f['positions'] = array_values( array_filter( $f['positions'], static function ( $old ) use ( $date, $topic ) {
                    return ! ( $old['date'] === $date && self::fold( $old['topic'] ) === self::fold( $topic ) );
                } ) );
                $f['positions'][] = [ 'date' => $date, 'topic' => $topic, 'position' => (string) $pos['position'], 'url' => $url ];
                $stats['positions']++;
            }
            if ( ! in_array( $source, $f['seen_in'], true ) ) {
                $f['seen_in'][] = $source;
                $f['mentions']++;
            }
            if ( '' === $f['first_seen'] || $date < $f['first_seen'] ) {
                $f['first_seen'] = $date;
            }
            if ( $date > $f['last_seen'] ) {
                $f['last_seen'] = $date;
            }
            $fiches[ $slug ] = self::normalize( $f );
            $stats[ $is_new ? 'created' : 'updated' ]++;
        }
        self::store( $fiches );
        return $stats;
    }

    /** Editor save: marks the fiche edited (kept on rebuild). */
    public static function save_edit( string $slug, array $data, int $user_id = 0 ) {
        $fiches = self::all();
        if ( ! isset( $fiches[ $slug ] ) ) {
            return new WP_Error( 'stakeholder_not_found', __( 'Fiche not found.', 'presshub-ai-editor' ) );
        }
        $f = $fiches[ $slug ];
        foreach ( [ 'name', 'name_en', 'role', 'party', 'bio' ] as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $f[ $field ] = (string) $data[ $field ];
            }
        }
        if ( '' === trim( (string) $f['name'] ) ) {
            return new WP_Error( 'stakeholder_name_required', __( 'A fiche needs a name.', 'presshub-ai-editor' ) );
        }
        if ( array_key_exists( 'aliases', $data ) ) {
            $f['aliases'] = is_array( $data['aliases'] ) ? $data['aliases'] : array_map( 'trim', explode( ',', (string) $data['aliases'] ) );
        }
        if ( array_key_exists( 'positions', $data ) ) {
            $f['positions'] = is_array( $data['positions'] ) ? $data['positions'] : self::parse_positions_text( (string) $data['positions'] );
        }
        $f['locked']     = ! empty( $data['locked'] );
        $f['excluded']   = ! empty( $data['excluded'] );
        $f['origin']     = 'edited';
        $f['updated_by'] = $user_id;
        $f['updated_at'] = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        $fiches[ $slug ] = self::normalize( $f );
        self::store( $fiches );
        return $fiches[ $slug ];
    }

    /** Merge fiche $from into $into (aliases, positions, sightings); $from is removed. */
    public static function merge_fiches( string $into, string $from, int $user_id = 0 ) {
        $fiches = self::all();
        if ( $into === $from || ! isset( $fiches[ $into ], $fiches[ $from ] ) ) {
            return new WP_Error( 'stakeholder_merge_invalid', __( 'Pick two different fiches to merge.', 'presshub-ai-editor' ) );
        }
        $a = $fiches[ $into ];
        $b = $fiches[ $from ];
        $a['aliases']    = array_merge( $a['aliases'], [ $b['name'] ], '' !== $b['name_en'] ? [ $b['name_en'] ] : [], $b['aliases'] );
        $a['positions']  = array_merge( $a['positions'], $b['positions'] );
        $shared          = count( array_intersect( $a['seen_in'], $b['seen_in'] ) );
        $a['seen_in']    = array_values( array_unique( array_merge( $a['seen_in'], $b['seen_in'] ) ) );
        // seen_in keeps only the last 20 editions, so take the larger of the two counts.
        $a['mentions']   = max( count( $a['seen_in'] ), $a['mentions'] + $b['mentions'] - $shared );
        $a['first_seen'] = min( array_filter( [ $a['first_seen'], $b['first_seen'] ] ) ?: [ '' ] );
        $a['last_seen']  = max( $a['last_seen'], $b['last_seen'] );
        foreach ( [ 'role', 'party', 'bio', 'name_en' ] as $field ) {
            if ( '' === $a[ $field ] ) {
                $a[ $field ] = $b[ $field ];
            }
        }
        $a['origin']     = 'edited';
        $a['updated_by'] = $user_id;
        $a['updated_at'] = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
        // Drop the primary name from the alias list if it came back in.
        $a['aliases'] = array_values( array_filter( $a['aliases'], static function ( $x ) use ( $a ) {
            return self::fold( $x ) !== self::fold( $a['name'] );
        } ) );
        $fiches[ $into ] = self::normalize( $a );
        unset( $fiches[ $from ] );
        self::store( $fiches );
        return $fiches[ $into ];
    }

    /** "YYYY-MM-DD | topic | position | url" per line. */
    public static function parse_positions_text( string $text ): array {
        $out = [];
        foreach ( preg_split( '/\r\n|\r|\n/', $text ) ?: [] as $line ) {
            $parts = array_map( 'trim', explode( '|', $line ) );
            if ( count( $parts ) < 3 || '' === $parts[2] ) {
                continue;
            }
            $out[] = [ 'date' => $parts[0], 'topic' => $parts[1], 'position' => $parts[2], 'url' => $parts[3] ?? '' ];
        }
        return $out;
    }

    public static function positions_text( array $positions ): string {
        $lines = [];
        foreach ( $positions as $p ) {
            $lines[] = implode( ' | ', array_filter( [ $p['date'], $p['topic'], $p['position'], $p['url'] ], static function ( $v ) {
                return null !== $v;
            } ) );
        }
        return implode( "\n", $lines );
    }

    // ---------------------------------------------------------------------
    // Extraction
    // ---------------------------------------------------------------------

    /** Parse the model's JSON reply into a people list, or WP_Error. */
    public static function parse_response( $raw ) {
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }
        $text = trim( (string) $raw );
        $text = preg_replace( '/^```(?:json)?\s*|\s*```$/u', '', $text ) ?? $text;
        $data = json_decode( $text, true );
        if ( ! is_array( $data ) && preg_match( '/\{.*\}/su', $text, $m ) ) {
            $data = json_decode( $m[0], true );
        }
        if ( ! is_array( $data ) || ! isset( $data['people'] ) || ! is_array( $data['people'] ) ) {
            return new WP_Error( 'stakeholders_invalid_json', __( 'The reply did not contain a people list.', 'presshub-ai-editor' ) );
        }
        return array_values( array_filter( $data['people'], 'is_array' ) );
    }

    /**
     * Extract the people from a published brief and merge them into the fiches.
     *
     * @return array|WP_Error Merge stats plus 'record_id'.
     */
    public static function extract_for_post( int $post_id, ?PressHub_AI_API_Client $api_client = null ) {
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

        $known = [];
        foreach ( self::all() as $f ) {
            if ( '' !== $f['last_seen'] && $f['last_seen'] >= gmdate( 'Y-m-d', strtotime( $date . ' -60 days' ) ) ) {
                $known[] = $f['name'] . ( '' !== $f['role'] ? ' (' . $f['role'] . ')' : '' );
            }
        }
        $known_str     = empty( $known ) ? 'Κανένα.' : implode( '; ', array_slice( $known, 0, 80 ) );
        $edition_label = 'noon' === $edition ? 'Μεσημβρινή Ενημέρωση' : 'Πρωινή Ενημέρωση';
        $sys           = str_replace(
            [ '{date}', '{edition}', '{known_people}' ],
            [ $date, $edition_label, $known_str ],
            PressHub_AI_Prompt_Loader::load( 'memory/stakeholders.txt' )
        );
        $user = "Ημερομηνία: {$date}\nΈκδοση: {$edition_label}\n\nΚείμενο Ενημέρωσης:\n{$text}";

        if ( null === $api_client ) {
            $api_client = new PressHub_AI_API_Client( 'briefing_memory' );
        }
        if ( method_exists( $api_client, 'set_action' ) ) {
            $api_client->set_action( 'memory_stakeholders' );
        }

        $t0  = class_exists( 'PressHub_AI_Generation_Recorder' ) ? PressHub_AI_Generation_Recorder::begin_call() : microtime( true );
        $raw = $api_client->call_provider( $sys, $user, true, [], null, [ 'action_trigger' => 'memory_stakeholders', 'briefing_date' => $date, 'edition' => $edition ] );
        $rec = 0;
        if ( class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
            $rec = (int) PressHub_AI_Generation_Recorder::finish_call( $t0, 'memory_stakeholders', $api_client, $sys, $user, $raw, [
                'post_id' => $post_id,
                'inputs'  => [ 'source_post_id' => $post_id, 'known_people' => count( $known ) ],
                'config'  => [ 'edition' => $edition ],
            ] );
        }

        $people = self::parse_response( $raw );
        if ( is_wp_error( $people ) ) {
            return $people;
        }
        $url   = function_exists( 'get_permalink' ) ? (string) get_permalink( $post_id ) : '';
        $stats = self::merge_people( $people, $date, $date . '-' . $edition, $url );
        $stats['record_id'] = $rec;
        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::info( 'Stakeholder fiches updated', array_merge( $stats, [ 'post_id' => $post_id, 'date' => $date, 'edition' => $edition ] ) );
        }
        return $stats;
    }

    // ---------------------------------------------------------------------
    // Injection
    // ---------------------------------------------------------------------

    public static function budget_for( string $action ): int {
        $base    = class_exists( 'PressHub_AI_Settings_Storage' ) ? PressHub_AI_Settings_Storage::get_context_budgets( 'presshub_ai_stakeholders_budgets', self::BUDGETS ) : self::BUDGETS;
        $budgets = function_exists( 'apply_filters' ) ? apply_filters( 'presshub_ai_stakeholders_budgets', $base ) : $base;
        return max( 0, (int) ( ( is_array( $budgets ) ? $budgets : $base )[ $action ] ?? 0 ) );
    }

    /** Fiches of the people that appear in $text, most mentioned in the text first. */
    public static function find_in_text( string $text, ?array $fiches = null ): array {
        $folded = self::fold( $text );
        $hits   = [];
        foreach ( ( $fiches ?? self::all() ) as $slug => $f ) {
            if ( $f['excluded'] ) {
                continue;
            }
            $count = 0;
            foreach ( array_merge( [ $f['name'], $f['name_en'] ], $f['aliases'] ) as $n ) {
                $re = '' !== trim( (string) $n ) ? self::name_pattern( (string) $n ) : '';
                if ( '' !== $re ) {
                    $count += (int) preg_match_all( $re, $folded );
                }
            }
            if ( $count > 0 ) {
                $hits[ $slug ] = [ 'count' => $count, 'fiche' => $f ];
            }
        }
        uasort( $hits, static function ( $a, $b ) {
            return [ $b['count'], $b['fiche']['mentions'] ] <=> [ $a['count'], $a['fiche']['mentions'] ];
        } );
        return $hits;
    }

    public static function render( array $hits, int $budget ): string {
        return self::render_report( $hits, $budget )['text'];
    }

    /**
     * The people block plus what the budget cut. The budget counts the
     * fiches' own lines; the fixed header and footer are not charged to it.
     *
     * @return array{text:string,people:string[],left_out:array}
     */
    public static function render_report( array $hits, int $budget ): array {
        if ( $budget <= 0 || empty( $hits ) ) {
            return [ 'text' => '', 'people' => [], 'left_out' => [] ];
        }
        $header   = "=== ΦΑΚΕΛΟΙ ΠΡΟΣΩΠΩΝ (από τη συντακτική μνήμη) ===\n"
            . "Πρόσωπα που εμφανίζονται στο σημερινό υλικό, με την ιδιότητά τους και τις τελευταίες καταγεγραμμένες θέσεις τους. Χρησιμοποίησέ τα για σωστούς τίτλους και ιδιότητες και για να επισημαίνεις όταν κάποιος αλλάζει θέση. Οι παλαιότερες θέσεις δεν είναι σημερινές ειδήσεις.\n";
        $footer   = '=== ΤΕΛΟΣ ΦΑΚΕΛΩΝ ===';
        $body     = '';
        $people   = [];
        $left_out = [];
        $position = 0;
        $full     = false;
        foreach ( $hits as $key => $hit ) {
            $key = (string) $key;
            if ( $position++ >= self::MAX_INJECTED ) {
                $left_out[] = [ 'item' => $key, 'reason' => 'max_items', 'limit' => self::MAX_INJECTED ];
                continue;
            }
            $f    = $hit['fiche'];
            $head = '- ' . $f['name'] . ( '' !== $f['name_en'] ? ' / ' . $f['name_en'] : '' );
            $meta = array_filter( [ $f['role'], $f['party'] ] );
            if ( ! empty( $meta ) ) {
                $head .= ' — ' . implode( ', ', $meta );
            }
            $lines = [ $head ];
            if ( '' !== $f['bio'] ) {
                $lines[] = '  ' . $f['bio'];
            }
            foreach ( array_slice( $f['positions'], 0, self::POSITIONS_PER_INJECT ) as $p ) {
                $lines[] = '  [' . $p['date'] . '] ' . ( '' !== $p['topic'] ? $p['topic'] . ': ' : '' ) . $p['position'];
            }
            $block = implode( "\n", $lines ) . "\n";
            $need  = self::len( $block );
            if ( $full ) {
                // Once one person did not fit, the rest are left out too (the
                // most mentioned come first).
                $left_out[] = [ 'item' => $key, 'reason' => 'no_room', 'limit' => $budget, 'room' => max( 0, $budget - self::len( $body ) ), 'needed' => $need ];
                continue;
            }
            if ( self::len( $body . $block ) > $budget ) {
                // Try the bare line (name and role) before giving up on this person.
                if ( self::len( $body . $head . "\n" ) > $budget ) {
                    $left_out[] = [ 'item' => $key, 'reason' => 'no_room', 'limit' => $budget, 'room' => max( 0, $budget - self::len( $body ) ), 'needed' => $need ];
                    $full       = true;
                    continue;
                }
                $block      = $head . "\n";
                $left_out[] = [ 'item' => $key, 'reason' => 'shortened', 'parts' => array_values( array_filter( [ '' !== $f['bio'] ? 'bio' : '', ! empty( $f['positions'] ) ? 'positions' : '' ] ) ), 'limit' => $budget, 'needed' => $need ];
            }
            $body    .= $block;
            $people[] = $key;
        }
        return [ 'text' => empty( $people ) ? '' : $header . $body . $footer, 'people' => $people, 'left_out' => $left_out ];
    }

    /**
     * Add the fiches of the people in $user_prompt to $sys_prompt for budgeted
     * actions. Never throws.
     */
    public static function apply( $sys_prompt, $user_prompt, string $action ) {
        self::$last_applied = null;
        if ( ! is_string( $sys_prompt ) || ! is_string( $user_prompt ) ) {
            return $sys_prompt;
        }
        try {
            $budget = self::budget_for( $action );
            if ( $budget <= 0 || ! self::is_enabled() ) {
                return $sys_prompt;
            }
            $hits   = self::find_in_text( $user_prompt );
            $report = self::render_report( $hits, $budget );
            $block  = $report['text'];
            if ( '' === $block && empty( $report['left_out'] ) ) {
                return $sys_prompt;
            }
            $out                = '' === $block ? $sys_prompt : rtrim( $sys_prompt ) . "\n\n" . $block;
            self::$last_applied = [
                'action'        => $action,
                'people'        => $report['people'],
                'chars'         => self::len( $block ),
                'left_out'      => $report['left_out'],
                'original_hash' => md5( $sys_prompt ),
                'prompt'        => $out,
            ];
            class_exists( 'PressHub_AI_Knowledge_Cuts' ) && PressHub_AI_Knowledge_Cuts::log( 'stakeholders', $action, $report['left_out'] );
            return $out;
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
    // Admin (PressHub → People)
    // ---------------------------------------------------------------------

    private static function guard(): void {
        $cap = class_exists( 'PressHub_AI_Memory_Editor' ) ? PressHub_AI_Memory_Editor::CAPABILITY : 'edit_others_posts';
        if ( ! current_user_can( $cap ) ) {
            wp_die( esc_html__( 'You do not have permission to edit stakeholder fiches.', 'presshub-ai-editor' ), 403 );
        }
        check_admin_referer( self::NONCE );
    }

    /** Admin page of the fiches (PressHub → People). */
    public static function page_slug(): string {
        return class_exists( 'PressHub_AI_Fiches_Admin' ) ? PressHub_AI_Fiches_Admin::PEOPLE_SLUG : 'presshub-ai-people';
    }

    private static function back( array $args ): void {
        wp_safe_redirect( add_query_arg( array_merge( [ 'page' => self::page_slug() ], $args ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_save(): void {
        self::guard();
        $post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
        $slug = sanitize_text_field( (string) ( $post['fiche'] ?? '' ) );
        $res  = self::save_edit( $slug, [
            'name'      => sanitize_text_field( (string) ( $post['name'] ?? '' ) ),
            'name_en'   => sanitize_text_field( (string) ( $post['name_en'] ?? '' ) ),
            'aliases'   => sanitize_text_field( (string) ( $post['aliases'] ?? '' ) ),
            'role'      => sanitize_text_field( (string) ( $post['role'] ?? '' ) ),
            'party'     => sanitize_text_field( (string) ( $post['party'] ?? '' ) ),
            'bio'       => sanitize_textarea_field( (string) ( $post['bio'] ?? '' ) ),
            'positions' => sanitize_textarea_field( (string) ( $post['positions'] ?? '' ) ),
            'locked'    => ! empty( $post['locked'] ),
            'excluded'  => ! empty( $post['excluded'] ),
        ], (int) get_current_user_id() );
        self::back( [ 'fiche' => $slug, 'sh_notice' => is_wp_error( $res ) ? $res->get_error_code() : 'saved' ] );
    }

    public static function handle_merge(): void {
        self::guard();
        $post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
        $into = sanitize_text_field( (string) ( $post['fiche'] ?? '' ) );
        $from = sanitize_text_field( (string) ( $post['merge_from'] ?? '' ) );
        $res  = self::merge_fiches( $into, $from, (int) get_current_user_id() );
        self::back( [ 'fiche' => $into, 'sh_notice' => is_wp_error( $res ) ? $res->get_error_code() : 'merged' ] );
    }

    private static function hidden( string $action, string $slug ): string {
        $nonce = function_exists( 'wp_nonce_field' ) ? wp_nonce_field( self::NONCE, '_wpnonce', true, false ) : '';
        return '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="fiche" value="' . esc_attr( $slug ) . '">' . $nonce;
    }

    public static function build_tab( array $query = [] ): string {
        $fiches  = self::all();
        $base    = add_query_arg( [ 'page' => self::page_slug() ], admin_url( 'admin.php' ) );
        $notices = [
            'saved'                     => __( 'Fiche saved. It is now marked as edited and keeps your changes on rebuild.', 'presshub-ai-editor' ),
            'merged'                    => __( 'Fiches merged.', 'presshub-ai-editor' ),
            'stakeholder_not_found'     => __( 'Fiche not found.', 'presshub-ai-editor' ),
            'stakeholder_name_required' => __( 'A fiche needs a name.', 'presshub-ai-editor' ),
            'stakeholder_merge_invalid' => __( 'Pick two different fiches to merge.', 'presshub-ai-editor' ),
        ];
        $html   = '';
        $notice = isset( $query['sh_notice'] ) ? sanitize_key( (string) $query['sh_notice'] ) : '';
        if ( isset( $notices[ $notice ] ) ) {
            $ok    = in_array( $notice, [ 'saved', 'merged' ], true );
            $html .= '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' inline"><p>' . esc_html( $notices[ $notice ] ) . '</p></div>';
        }
        $html .= '<p class="description">' . esc_html__( 'Fiches for the people in the news, built from each published brief. The fiches of people who appear in the day\'s material are added to the curation and podcast-script prompts (role and latest positions). Edited fiches keep your changes; locked fiches are never changed automatically; excluded fiches are never used.', 'presshub-ai-editor' ) . '</p>';

        $open = isset( $query['fiche'] ) ? sanitize_text_field( (string) $query['fiche'] ) : '';
        if ( '' !== $open && isset( $fiches[ $open ] ) ) {
            return $html . self::edit_form( $open, $fiches, $base );
        }

        if ( empty( $fiches ) ) {
            return $html . '<p>' . esc_html__( 'No fiches yet. They are built after the next brief is published.', 'presshub-ai-editor' ) . '</p>';
        }
        uasort( $fiches, static function ( $a, $b ) {
            return [ $b['last_seen'], $b['mentions'] ] <=> [ $a['last_seen'], $a['mentions'] ];
        } );
        $html .= '<table class="widefat striped presshub-stakeholders"><thead><tr><th>' . esc_html__( 'Name', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Role', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Latest position', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Editions', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Last seen', 'presshub-ai-editor' ) . '</th><th>' . esc_html__( 'Status', 'presshub-ai-editor' ) . '</th></tr></thead><tbody>';
        foreach ( $fiches as $slug => $f ) {
            $flags = array_filter( [ $f['locked'] ? __( 'locked', 'presshub-ai-editor' ) : '', $f['excluded'] ? __( 'excluded', 'presshub-ai-editor' ) : '', 'edited' === $f['origin'] ? __( 'edited', 'presshub-ai-editor' ) : '' ] );
            $latest = $f['positions'][0] ?? null;
            $html  .= '<tr><td><a href="' . esc_url( add_query_arg( [ 'fiche' => $slug ], $base ) ) . '"><strong>' . esc_html( $f['name'] ) . '</strong></a>' . ( '' !== $f['name_en'] ? '<br><span class="description">' . esc_html( $f['name_en'] ) . '</span>' : '' ) . '</td>'
                . '<td>' . esc_html( trim( $f['role'] . ( '' !== $f['party'] ? ' · ' . $f['party'] : '' ) ) ) . '</td>'
                . '<td>' . ( $latest ? esc_html( '[' . $latest['date'] . '] ' . ( '' !== $latest['topic'] ? $latest['topic'] . ': ' : '' ) . $latest['position'] ) : '' ) . '</td>'
                . '<td>' . (int) $f['mentions'] . '</td><td>' . esc_html( $f['last_seen'] ) . '</td><td>' . esc_html( implode( ', ', $flags ) ) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    private static function edit_form( string $slug, array $fiches, string $base ): string {
        $f    = $fiches[ $slug ];
        $url  = esc_url( admin_url( 'admin-post.php' ) );
        $row  = static function ( string $label, string $field_html ): string {
            return '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $field_html . '</td></tr>';
        };
        $html  = '<p><a href="' . esc_url( $base ) . '">&larr; ' . esc_html__( 'All fiches', 'presshub-ai-editor' ) . '</a></p>';
        $html .= '<h2>' . esc_html( $f['name'] ) . '</h2>';
        $html .= '<p class="description">' . esc_html( sprintf( /* translators: 1: first seen, 2: last seen, 3: editions */ __( 'First seen %1$s, last seen %2$s, in %3$d editions.', 'presshub-ai-editor' ), $f['first_seen'], $f['last_seen'], $f['mentions'] ) ) . '</p>';
        $html .= '<form method="post" action="' . $url . '">' . self::hidden( self::POST_SAVE, $slug ) . '<table class="form-table"><tbody>';
        $html .= $row( __( 'Name', 'presshub-ai-editor' ), '<input type="text" class="regular-text" name="name" value="' . esc_attr( $f['name'] ) . '" required>' );
        $html .= $row( __( 'English name', 'presshub-ai-editor' ), '<input type="text" class="regular-text" name="name_en" value="' . esc_attr( $f['name_en'] ) . '">' );
        $html .= $row( __( 'Aliases (comma separated)', 'presshub-ai-editor' ), '<input type="text" class="large-text" name="aliases" value="' . esc_attr( implode( ', ', $f['aliases'] ) ) . '">' );
        $html .= $row( __( 'Role / office', 'presshub-ai-editor' ), '<input type="text" class="regular-text" name="role" value="' . esc_attr( $f['role'] ) . '">' );
        $html .= $row( __( 'Party / organisation', 'presshub-ai-editor' ), '<input type="text" class="regular-text" name="party" value="' . esc_attr( $f['party'] ) . '">' );
        $html .= $row( __( 'Bio line', 'presshub-ai-editor' ), '<textarea name="bio" rows="2" class="large-text">' . esc_textarea( $f['bio'] ) . '</textarea>' );
        $html .= $row( __( 'Positions (date | topic | position | link, one per line, newest first)', 'presshub-ai-editor' ), '<textarea name="positions" rows="8" class="large-text code">' . esc_textarea( self::positions_text( $f['positions'] ) ) . '</textarea>' );
        $html .= $row( __( 'Options', 'presshub-ai-editor' ), '<label><input type="checkbox" name="locked" value="1"' . ( $f['locked'] ? ' checked' : '' ) . '> ' . esc_html__( 'Locked (never changed automatically)', 'presshub-ai-editor' ) . '</label><br><label><input type="checkbox" name="excluded" value="1"' . ( $f['excluded'] ? ' checked' : '' ) . '> ' . esc_html__( 'Excluded (never added to prompts)', 'presshub-ai-editor' ) . '</label>' );
        $html .= '</tbody></table><p><button type="submit" class="button button-primary">' . esc_html__( 'Save fiche', 'presshub-ai-editor' ) . '</button></p></form>';

        $others = array_diff_key( $fiches, [ $slug => true ] );
        if ( ! empty( $others ) ) {
            uasort( $others, static function ( $a, $b ) {
                return strcmp( $a['name'], $b['name'] );
            } );
            $html .= '<h3>' . esc_html__( 'Merge a duplicate into this fiche', 'presshub-ai-editor' ) . '</h3><form method="post" action="' . $url . '">' . self::hidden( self::POST_MERGE, $slug ) . '<select name="merge_from">';
            foreach ( $others as $oslug => $o ) {
                $html .= '<option value="' . esc_attr( (string) $oslug ) . '">' . esc_html( $o['name'] . ( '' !== $o['role'] ? ' (' . $o['role'] . ')' : '' ) ) . '</option>';
            }
            $html .= '</select> <button type="submit" class="button">' . esc_html__( 'Merge into this fiche', 'presshub-ai-editor' ) . '</button><p class="description">' . esc_html__( 'The other fiche\'s names, positions and sightings move here and the other fiche is removed.', 'presshub-ai-editor' ) . '</p></form>';
        }
        return $html;
    }
}
