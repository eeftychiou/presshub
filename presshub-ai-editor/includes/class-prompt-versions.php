<?php
/**
 * PressHub_AI_Prompt_Versions — history of the prompt studio's prompts.
 *
 * Every time an editable prompt changes (a Save in Settings, a seeded or
 * upgraded default, an import), the new text is kept as a numbered version of
 * that prompt. Generations that use a prompt name the exact version they used:
 * the briefing text, the podcast script, the Shorts script and the social copy
 * store a `prompt_versions` list on their generation record, and each use is
 * kept in a small link table so the history can say which generations (and so
 * which posts) a version produced.
 *
 * The history is read only. Bringing an old version back means loading it into
 * the prompt's own field and saving the section: the settings Save stays the
 * one writer of the setting (settings design principles 1.3 and 3.1), and the
 * save becomes a new version marked "restored from vN".
 *
 * Diffs are word level inside changed lines: removed words are struck through,
 * added words are bold (`<del>` / `<ins>`), so the change does not depend on
 * colour alone.
 *
 * @package PressHub_AI_Editor
 * @since 2.81.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_AI_Prompt_Versions {

    const DB_VERSION        = '1.0.0';
    const DB_VERSION_OPTION = 'presshub_ai_prompt_versions_db_version';

    /** Set once the prompts in place when the history began have been kept. */
    const BASELINE_OPTION = 'presshub_ai_prompt_versions_baseline';

    const NONCE_ACTION = 'presshub_ai_nonce';
    const AJAX_LIST    = 'presshub_ai_prompt_versions';
    const AJAX_GET     = 'presshub_ai_prompt_version';
    const AJAX_DIFF    = 'presshub_ai_prompt_version_diff';

    /** How many generations a version lists in the history. */
    const USES_LIMIT = 20;

    /** Largest LCS table (cells) a diff builds before it compares whole blocks. */
    const DIFF_MAX_CELLS = 1500000;

    /** Podcast dialogue styles and their labels. */
    const PODCAST_STYLES = [
        'default_greek_chat'            => 'Default Greek Chat',
        'bbc_broadcasting_standards'    => 'BBC Broadcasting Standards',
        'conversational_news_reporting' => 'Conversational News Reporting',
    ];

    /** Social copy platforms with an editable caption prompt. */
    const SOCIAL_PLATFORMS = [
        'twitter'   => 'X/Twitter',
        'facebook'  => 'Facebook',
        'instagram' => 'Instagram',
        'tiktok'    => 'TikTok',
        'youtube'   => 'YouTube',
    ];

    /** Sources a version can have. */
    const SOURCES = [ 'baseline', 'builtin', 'saved', 'restored' ];

    /** Re-entrancy guard for the option hooks. */
    private static bool $hooks_registered = false;

    /** @var array<string,array>|null */
    private static ?array $tracked_cache = null;

    // ------------------------------------------------------------------
    // Tables.
    // ------------------------------------------------------------------

    public static function versions_table(): string {
        global $wpdb;
        $prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
        return $prefix . 'presshub_ai_prompt_versions';
    }

    public static function uses_table(): string {
        global $wpdb;
        $prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_';
        return $prefix . 'presshub_ai_prompt_version_uses';
    }

    public static function create_tables(): void {
        global $wpdb;
        $charset_collate = ( isset( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) ) ? $wpdb->get_charset_collate() : '';
        $versions        = self::versions_table();
        $uses            = self::uses_table();

        $sql = [
            "CREATE TABLE {$versions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            prompt_key varchar(100) NOT NULL,
            version int(10) unsigned NOT NULL DEFAULT 1,
            content_hash char(32) NOT NULL DEFAULT '',
            content longtext NOT NULL,
            source varchar(16) NOT NULL DEFAULT 'saved',
            restored_from int(10) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY prompt_key (prompt_key),
            KEY content_hash (content_hash)
        ) {$charset_collate};",
            "CREATE TABLE {$uses} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            version_id bigint(20) unsigned NOT NULL,
            record_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY version_id (version_id),
            KEY record_id (record_id),
            KEY created_at (created_at)
        ) {$charset_collate};",
        ];

        if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        foreach ( $sql as $statement ) {
            if ( function_exists( 'dbDelta' ) ) {
                dbDelta( $statement );
            } elseif ( isset( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
                $wpdb->query( $statement );
            }
        }
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    /**
     * Create the tables when missing and keep, once, the prompts in place when
     * the history began (so the first saved edit has something to compare to).
     */
    public static function maybe_upgrade(): void {
        if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
            self::create_tables();
        }
        if ( '1' !== (string) get_option( self::BASELINE_OPTION, '' ) ) {
            update_option( self::BASELINE_OPTION, '1', false );
            foreach ( array_keys( self::tracked() ) as $key ) {
                $text = self::current_text( $key );
                if ( '' !== $text ) {
                    self::store_if_changed( $key, $text, 'baseline', 0 );
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Which prompts are tracked.
    // ------------------------------------------------------------------

    /**
     * The prompts with a history, keyed by option name. Built without
     * translations (the option hooks are added while the plugin loads, before
     * the text domain); label() gives the translated name.
     *
     * default: callable returning the shipped default text ('' when none).
     * empty_uses_default: an emptied option means "use the shipped default"
     *          (curator and QA prompts); otherwise empty means "off".
     * social:  edited in Social publishing (capability PressHub_Capabilities::social()).
     *
     * @return array<string,array{default:callable,empty_uses_default:bool,social:bool}>
     */
    public static function tracked(): array {
        if ( null !== self::$tracked_cache ) {
            return self::$tracked_cache;
        }
        $core = static function ( string $method ): callable {
            return static function () use ( $method ): string {
                if ( ! class_exists( 'PressHub_AI_Prompt_Loader' ) ) {
                    require_once __DIR__ . '/class-prompt-loader.php';
                }
                return (string) PressHub_AI_Prompt_Loader::$method();
            };
        };
        $social = static function ( string $file ): callable {
            return static function () use ( $file ): string {
                if ( ! class_exists( 'PressHub_Social_Prompt_Loader' ) && file_exists( __DIR__ . '/class-social-prompt-loader.php' ) ) {
                    require_once __DIR__ . '/class-social-prompt-loader.php';
                }
                return class_exists( 'PressHub_Social_Prompt_Loader' ) ? (string) PressHub_Social_Prompt_Loader::load( $file ) : '';
            };
        };
        $entry = static function ( callable $default, bool $empty_uses_default, bool $social ): array {
            return [ 'default' => $default, 'empty_uses_default' => $empty_uses_default, 'social' => $social ];
        };

        $tracked = [
            'presshub_ai_briefing_text_prompt' => $entry( $core( 'get_curation_prompt' ), true, false ),
        ];
        foreach ( array_keys( self::PODCAST_STYLES ) as $style ) {
            foreach ( [ 1, 2, 3 ] as $n ) {
                $tracked[ self::podcast_key( $n, $style ) ] = $entry( static function () use ( $n, $style ): string {
                    if ( ! class_exists( 'PressHub_AI_Prompt_Loader' ) ) {
                        require_once __DIR__ . '/class-prompt-loader.php';
                    }
                    return (string) PressHub_AI_Prompt_Loader::get_podcast_prompt( $n, $style );
                }, false, false );
            }
        }
        $tracked['presshub_social_prompt_tiktok_shorts']        = $entry( $social( 'social_tiktok_shorts.txt' ), false, true );
        $tracked['presshub_social_prompt_tiktok_article_short'] = $entry( $social( 'social_tiktok_article_short.txt' ), false, true );
        $tracked['presshub_social_prompt_system']               = $entry( $social( 'social_system.txt' ), false, true );
        foreach ( array_keys( self::SOCIAL_PLATFORMS ) as $platform ) {
            $tracked[ 'presshub_social_prompt_' . $platform ] = $entry( $social( 'social_copy_' . $platform . '.txt' ), false, true );
        }
        $tracked['presshub_ai_qa_article_prompt']  = $entry( $core( 'get_article_qa_prompt' ), true, false );
        $tracked['presshub_ai_qa_briefing_prompt'] = $entry( $core( 'get_briefing_qa_prompt' ), true, false );

        self::$tracked_cache = $tracked;
        return $tracked;
    }

    public static function is_tracked( string $key ): bool {
        return isset( self::tracked()[ $key ] );
    }

    /** What the history calls a prompt (translated). */
    public static function label( string $key ): string {
        switch ( $key ) {
            case 'presshub_ai_briefing_text_prompt':
                return __( 'Daily Briefing: curator system prompt', 'presshub-ai-editor' );
            case 'presshub_social_prompt_tiktok_shorts':
                return __( 'Shorts: podcast script prompt', 'presshub-ai-editor' );
            case 'presshub_social_prompt_tiktok_article_short':
                return __( 'Shorts: article script prompt', 'presshub-ai-editor' );
            case 'presshub_social_prompt_system':
                return __( 'Social posts: system prompt', 'presshub-ai-editor' );
            case 'presshub_ai_qa_article_prompt':
                return __( 'QA review: article prompt', 'presshub-ai-editor' );
            case 'presshub_ai_qa_briefing_prompt':
                return __( 'QA review: Daily Briefing prompt', 'presshub-ai-editor' );
        }
        if ( preg_match( '/^presshub_ai_briefing_podcast_prompt_([123])_([a-z_]+)$/', $key, $m ) && isset( self::PODCAST_STYLES[ $m[2] ] ) ) {
            $hosts = [
                '1' => __( '1 presenter', 'presshub-ai-editor' ),
                '2' => __( '2 presenters', 'presshub-ai-editor' ),
                '3' => __( '3 presenters', 'presshub-ai-editor' ),
            ];
            /* translators: 1: dialogue style, 2: number of presenters */
            return sprintf( __( 'Podcast script: %1$s, %2$s', 'presshub-ai-editor' ), self::PODCAST_STYLES[ $m[2] ], $hosts[ $m[1] ] );
        }
        if ( preg_match( '/^presshub_social_prompt_([a-z]+)$/', $key, $m ) && isset( self::SOCIAL_PLATFORMS[ $m[1] ] ) ) {
            /* translators: %s: social platform */
            return sprintf( __( 'Social posts: %s caption prompt', 'presshub-ai-editor' ), self::SOCIAL_PLATFORMS[ $m[1] ] );
        }
        return $key;
    }

    public static function podcast_key( int $host_count, string $style ): string {
        return 'presshub_ai_briefing_podcast_prompt_' . $host_count . '_' . $style;
    }

    /** The capability that may read a prompt's history. */
    public static function capability_for( string $key ): string {
        if ( ! class_exists( 'PressHub_Capabilities' ) ) {
            return 'edit_others_posts';
        }
        return ! empty( self::tracked()[ $key ]['social'] ) ? PressHub_Capabilities::social() : PressHub_Capabilities::manage();
    }

    /** One rule for every stored and compared text: LF line ends, no surrounding whitespace. */
    public static function normalize( $text ): string {
        return trim( str_replace( [ "\r\n", "\r" ], "\n", (string) $text ) );
    }

    public static function default_text( string $key ): string {
        $entry = self::tracked()[ $key ] ?? null;
        if ( null === $entry ) {
            return '';
        }
        try {
            return self::normalize( call_user_func( $entry['default'] ) );
        } catch ( \Throwable $t ) {
            return '';
        }
    }

    /**
     * The text a stored value stands for: itself, or the shipped default when
     * the prompt treats empty as "use the default". '' means nothing to keep.
     */
    public static function effective_text( string $key, $raw ): string {
        $text = self::normalize( is_string( $raw ) ? $raw : '' );
        if ( '' === $text && ! empty( self::tracked()[ $key ]['empty_uses_default'] ) ) {
            return self::default_text( $key );
        }
        return $text;
    }

    /** The text in force now (the stored value, or the default it falls back to). */
    public static function current_text( string $key ): string {
        $raw = get_option( $key, false );
        if ( false === $raw || null === $raw ) {
            return self::default_text( $key );
        }
        return self::effective_text( $key, $raw );
    }

    // ------------------------------------------------------------------
    // Keeping versions.
    // ------------------------------------------------------------------

    /** Hook every tracked option: a stored change becomes a version. */
    public static function register_hooks(): void {
        if ( self::$hooks_registered || ! function_exists( 'add_action' ) ) {
            return;
        }
        self::$hooks_registered = true;
        foreach ( array_keys( self::tracked() ) as $key ) {
            add_action( 'add_option_' . $key, [ __CLASS__, 'on_add_option' ], 20, 2 );
            add_action( 'update_option_' . $key, [ __CLASS__, 'on_update_option' ], 20, 3 );
        }
        add_action( 'wp_ajax_' . self::AJAX_LIST, [ __CLASS__, 'ajax_list' ] );
        add_action( 'wp_ajax_' . self::AJAX_GET, [ __CLASS__, 'ajax_get' ] );
        add_action( 'wp_ajax_' . self::AJAX_DIFF, [ __CLASS__, 'ajax_diff' ] );
        // Tables and the first history after the text domain is loaded.
        add_action( 'init', [ __CLASS__, 'maybe_upgrade' ], 20 );
    }

    /** add_option_{key}( $option, $value ). */
    public static function on_add_option( $option, $value = '' ): void {
        self::snapshot( (string) $option, $value );
    }

    /** update_option_{key}( $old, $value, $option ). */
    public static function on_update_option( $old_value, $value = '', $option = '' ): void {
        self::snapshot( (string) $option, $value );
    }

    /**
     * Keep the text a stored value stands for as a new version when it differs
     * from the latest one. Never throws: a history failure never blocks a save.
     *
     * @return array|null The latest version after the call, or null.
     */
    public static function snapshot( string $key, $raw ): ?array {
        try {
            if ( ! self::is_tracked( $key ) ) {
                return null;
            }
            // Before maybe_upgrade() has made the tables (a prompt seeded on
            // plugins_loaded, before init), the baseline keeps it instead.
            if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
                return null;
            }
            $text = self::effective_text( $key, $raw );
            if ( '' === $text ) {
                return self::latest( $key );
            }
            $user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
            return self::store_if_changed( $key, $text, '', $user_id );
        } catch ( \Throwable $t ) {
            self::log_failure( 'Prompt version snapshot failed: ' . $t->getMessage(), $key );
            return null;
        }
    }

    /**
     * Insert $text as the next version of $key unless it equals the latest.
     * An empty $source is worked out: identical to an earlier version →
     * restored, identical to the shipped default → builtin, else saved.
     */
    private static function store_if_changed( string $key, string $text, string $source, int $user_id ): ?array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return null;
        }
        $hash   = md5( $text );
        $latest = self::latest( $key );
        if ( null !== $latest && $latest['content_hash'] === $hash ) {
            return $latest;
        }

        $restored_from = 0;
        if ( '' === $source ) {
            $earlier = null !== $latest ? self::find_by_hash( $key, $hash ) : null;
            if ( null !== $earlier ) {
                $source        = 'restored';
                $restored_from = (int) $earlier['version'];
            } elseif ( self::default_text( $key ) === $text ) {
                $source = 'builtin';
            } else {
                $source = 'saved';
            }
        } elseif ( 'baseline' === $source && self::default_text( $key ) === $text ) {
            $source = 'builtin';
        }

        $row = [
            'prompt_key'    => $key,
            'version'       => null !== $latest ? (int) $latest['version'] + 1 : 1,
            'content_hash'  => $hash,
            'content'       => $text,
            'source'        => in_array( $source, self::SOURCES, true ) ? $source : 'saved',
            'restored_from' => $restored_from,
            'user_id'       => max( 0, $user_id ),
            'created_at'    => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
        ];
        $result = $wpdb->insert( self::versions_table(), $row, [ '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s' ] );
        if ( false === $result || empty( $wpdb->insert_id ) ) {
            self::log_failure( 'Prompt version insert failed', $key );
            return null;
        }
        $row['id'] = (int) $wpdb->insert_id;
        return self::normalize_row( $row );
    }

    // ------------------------------------------------------------------
    // Reading versions.
    // ------------------------------------------------------------------

    /** All versions of a prompt, newest first (content included). */
    public static function versions( string $key ): array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! self::is_tracked( $key ) ) {
            return [];
        }
        $sql  = $wpdb->prepare( 'SELECT * FROM ' . self::versions_table() . ' WHERE prompt_key = %s ORDER BY version DESC', $key );
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        return array_map( [ __CLASS__, 'normalize_row' ], is_array( $rows ) ? $rows : [] );
    }

    public static function latest( string $key ): ?array {
        $versions = self::versions( $key );
        return $versions[0] ?? null;
    }

    /** Newest version of $key with this hash. */
    public static function find_by_hash( string $key, string $hash ): ?array {
        foreach ( self::versions( $key ) as $version ) {
            if ( $version['content_hash'] === $hash ) {
                return $version;
            }
        }
        return null;
    }

    public static function get( int $id ): ?array {
        global $wpdb;
        if ( $id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return null;
        }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::versions_table() . ' WHERE id = %d', $id ), ARRAY_A );
        return is_array( $row ) && ! empty( $row ) ? self::normalize_row( $row ) : null;
    }

    private static function normalize_row( $row ): array {
        $row = (array) $row;
        return [
            'id'            => (int) ( $row['id'] ?? 0 ),
            'prompt_key'    => (string) ( $row['prompt_key'] ?? '' ),
            'version'       => (int) ( $row['version'] ?? 0 ),
            'content_hash'  => (string) ( $row['content_hash'] ?? '' ),
            'content'       => (string) ( $row['content'] ?? '' ),
            'source'        => (string) ( $row['source'] ?? 'saved' ),
            'restored_from' => (int) ( $row['restored_from'] ?? 0 ),
            'user_id'       => (int) ( $row['user_id'] ?? 0 ),
            'created_at'    => (string) ( $row['created_at'] ?? '' ),
        ];
    }

    // ------------------------------------------------------------------
    // Linking generations to versions.
    // ------------------------------------------------------------------

    /**
     * The version a generation is about to use. $text is the template as the
     * prompt studio holds it (before placeholders are filled). The newest
     * version with the same text is used; text with no version yet (the option
     * was written around WordPress) becomes one.
     *
     * @return array{key:string,label:string,version_id:int,version:int}|null
     */
    public static function ref( string $key, string $text ): ?array {
        try {
            if ( ! self::is_tracked( $key ) ) {
                return null;
            }
            $text = self::normalize( $text );
            if ( '' === $text ) {
                return null;
            }
            $hash    = md5( $text );
            $version = self::find_by_hash( $key, $hash );
            if ( null === $version ) {
                $version = self::store_if_changed( $key, $text, '', 0 );
            }
            if ( null === $version ) {
                return null;
            }
            return [
                'key'        => $key,
                'label'      => self::label( $key ),
                'version_id' => (int) $version['id'],
                'version'    => (int) $version['version'],
            ];
        } catch ( \Throwable $t ) {
            self::log_failure( 'Prompt version lookup failed: ' . $t->getMessage(), $key );
            return null;
        }
    }

    /** The refs worth keeping from a list (drops nulls and malformed entries). */
    public static function clean_refs( $refs ): array {
        $out = [];
        foreach ( is_array( $refs ) ? $refs : [] as $ref ) {
            if ( is_array( $ref ) && (int) ( $ref['version_id'] ?? 0 ) > 0 ) {
                $out[] = [
                    'key'        => (string) ( $ref['key'] ?? '' ),
                    'label'      => (string) ( $ref['label'] ?? '' ),
                    'version_id' => (int) $ref['version_id'],
                    'version'    => (int) ( $ref['version'] ?? 0 ),
                ];
            }
        }
        return $out;
    }

    /** Link a stored generation record to the versions it used. Never throws. */
    public static function record_uses( int $record_id, array $refs ): void {
        global $wpdb;
        if ( $record_id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return;
        }
        try {
            $now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
            $done = [];
            foreach ( self::clean_refs( $refs ) as $ref ) {
                if ( isset( $done[ $ref['version_id'] ] ) ) {
                    continue;
                }
                $done[ $ref['version_id'] ] = true;
                $wpdb->insert( self::uses_table(), [
                    'version_id' => $ref['version_id'],
                    'record_id'  => $record_id,
                    'created_at' => $now,
                ], [ '%d', '%d', '%s' ] );
            }
        } catch ( \Throwable $t ) {
            self::log_failure( 'Prompt version use insert failed: ' . $t->getMessage(), '' );
        }
    }

    /** Generation record ids that used a version, newest first. */
    public static function record_ids_for_version( int $version_id, int $limit = 0 ): array {
        global $wpdb;
        if ( $version_id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return [];
        }
        $sql = $wpdb->prepare( 'SELECT record_id FROM ' . self::uses_table() . ' WHERE version_id = %d ORDER BY record_id DESC', $version_id );
        if ( $limit > 0 ) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        $rows = $wpdb->get_results( $sql, ARRAY_A );
        $ids  = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $id = (int) ( is_array( $row ) ? ( $row['record_id'] ?? 0 ) : ( $row->record_id ?? 0 ) );
            if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** Number of generations that used a version. */
    public static function count_uses( int $version_id ): int {
        global $wpdb;
        if ( $version_id <= 0 || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return 0;
        }
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::uses_table() . ' WHERE version_id = %d', $version_id ) );
    }

    /** Drop links older than the generation records they point to. */
    public static function prune_uses( string $cutoff ): int {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return 0;
        }
        $deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::uses_table() . ' WHERE created_at < %s', $cutoff ) );
        return is_numeric( $deleted ) ? (int) $deleted : 0;
    }

    // ------------------------------------------------------------------
    // Where things are.
    // ------------------------------------------------------------------

    /** Settings URL of a prompt's field, opening its history (and a version). */
    public static function history_url( string $key, int $version_id = 0 ): string {
        $section = class_exists( 'PressHub_Settings_Sections' ) ? PressHub_Settings_Sections::section_of( $key ) : '';
        if ( '' === $section ) {
            $section = ! empty( self::tracked()[ $key ]['social'] ) ? 'social-publishing' : 'daily-briefing';
        }
        $args = [ 'page' => 'presshub-ai', 'section' => $section, 'prompt_history' => $key ];
        if ( $version_id > 0 ) {
            $args['prompt_version'] = $version_id;
        }
        $url = function_exists( 'add_query_arg' ) ? add_query_arg( $args, admin_url( 'admin.php' ) ) : admin_url( 'admin.php?' . http_build_query( $args ) );
        return $url . '#' . rawurlencode( $key );
    }

    /** Generation records page filtered to one version. */
    public static function records_url( int $version_id ): string {
        $args = [ 'page' => 'presshub-ai-generation-records', 'prompt_version' => $version_id ];
        return function_exists( 'add_query_arg' ) ? add_query_arg( $args, admin_url( 'admin.php' ) ) : admin_url( 'admin.php?' . http_build_query( $args ) );
    }

    public static function source_label( array $version ): string {
        switch ( $version['source'] ) {
            case 'baseline':
                return __( 'In place when history began', 'presshub-ai-editor' );
            case 'builtin':
                return __( 'Built-in default', 'presshub-ai-editor' );
            case 'restored':
                /* translators: %d: version number */
                return sprintf( __( 'Restored from v%d', 'presshub-ai-editor' ), (int) $version['restored_from'] );
        }
        return __( 'Saved', 'presshub-ai-editor' );
    }

    private static function user_name( int $user_id ): string {
        if ( $user_id <= 0 ) {
            return __( 'PressHub', 'presshub-ai-editor' );
        }
        if ( function_exists( 'get_userdata' ) ) {
            $user = get_userdata( $user_id );
            if ( is_object( $user ) && ! empty( $user->display_name ) ) {
                return (string) $user->display_name;
            }
        }
        return '#' . $user_id;
    }

    // ------------------------------------------------------------------
    // Diff.
    // ------------------------------------------------------------------

    /**
     * Word-level diff of two texts: a list of [op, text] with op '=', '-' or '+'.
     * Lines are matched first; the words of changed lines are then compared.
     *
     * @return array<int,array{0:string,1:string}>
     */
    public static function diff_ops( string $old, string $new ): array {
        $old = str_replace( [ "\r\n", "\r" ], "\n", $old );
        $new = str_replace( [ "\r\n", "\r" ], "\n", $new );
        if ( $old === $new ) {
            return '' === $old ? [] : [ [ '=', $old ] ];
        }
        $line_ops = self::lcs_ops( self::split_lines( $old ), self::split_lines( $new ) );

        $ops = [];
        $del = '';
        $ins = '';
        $flush = static function () use ( &$ops, &$del, &$ins ): void {
            if ( '' !== $del && '' !== $ins ) {
                foreach ( self::lcs_ops( self::split_words( $del ), self::split_words( $ins ) ) as $op ) {
                    $ops[] = $op;
                }
            } elseif ( '' !== $del ) {
                $ops[] = [ '-', $del ];
            } elseif ( '' !== $ins ) {
                $ops[] = [ '+', $ins ];
            }
            $del = '';
            $ins = '';
        };
        foreach ( $line_ops as $op ) {
            if ( '=' === $op[0] ) {
                $flush();
                $ops[] = $op;
            } elseif ( '-' === $op[0] ) {
                $del .= $op[1];
            } else {
                $ins .= $op[1];
            }
        }
        $flush();
        return self::merge_ops( $ops );
    }

    /** Diff as HTML: removed text struck through, added text bold. */
    public static function diff_html( string $old, string $new ): string {
        $html = '';
        foreach ( self::diff_ops( $old, $new ) as $op ) {
            $text = esc_html( $op[1] );
            if ( '-' === $op[0] ) {
                $html .= '<del class="presshub-pv-del">' . $text . '</del>';
            } elseif ( '+' === $op[0] ) {
                $html .= '<ins class="presshub-pv-ins">' . $text . '</ins>';
            } else {
                $html .= $text;
            }
        }
        return '<div class="presshub-pv-diff">' . $html . '</div>';
    }

    /** Words added and removed (whitespace not counted). */
    public static function diff_stats( string $old, string $new ): array {
        $stats = [ 'added' => 0, 'removed' => 0 ];
        foreach ( self::diff_ops( $old, $new ) as $op ) {
            if ( '=' === $op[0] ) {
                continue;
            }
            $words = preg_split( '/\s+/u', trim( $op[1] ), -1, PREG_SPLIT_NO_EMPTY );
            $stats[ '+' === $op[0] ? 'added' : 'removed' ] += is_array( $words ) ? count( $words ) : 0;
        }
        return $stats;
    }

    private static function split_lines( string $text ): array {
        if ( '' === $text ) {
            return [];
        }
        $parts = preg_split( '/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $parts ) ? $parts : [ $text ];
    }

    private static function split_words( string $text ): array {
        if ( '' === $text ) {
            return [];
        }
        $parts = preg_split( '/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
        return is_array( $parts ) ? $parts : [ $text ];
    }

    /**
     * Longest-common-subsequence diff of two token lists. The common head and
     * tail are skipped first; a middle too large for the table is compared as
     * one removed and one added block.
     */
    private static function lcs_ops( array $a, array $b ): array {
        $head = [];
        while ( ! empty( $a ) && ! empty( $b ) && $a[0] === $b[0] ) {
            $head[] = [ '=', array_shift( $a ) ];
            array_shift( $b );
        }
        $tail = [];
        while ( ! empty( $a ) && ! empty( $b ) && end( $a ) === end( $b ) ) {
            array_unshift( $tail, [ '=', array_pop( $a ) ] );
            array_pop( $b );
        }

        $n   = count( $a );
        $m   = count( $b );
        $mid = [];
        if ( 0 === $n ) {
            foreach ( $b as $t ) {
                $mid[] = [ '+', $t ];
            }
        } elseif ( 0 === $m ) {
            foreach ( $a as $t ) {
                $mid[] = [ '-', $t ];
            }
        } elseif ( $n * $m > self::DIFF_MAX_CELLS ) {
            $mid[] = [ '-', implode( '', $a ) ];
            $mid[] = [ '+', implode( '', $b ) ];
        } else {
            // Suffix LCS lengths, one row per token of $a.
            $len = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
            for ( $i = $n - 1; $i >= 0; $i-- ) {
                $row  = &$len[ $i ];
                $next = $len[ $i + 1 ];
                for ( $j = $m - 1; $j >= 0; $j-- ) {
                    $row[ $j ] = ( $a[ $i ] === $b[ $j ] ) ? $next[ $j + 1 ] + 1 : max( $next[ $j ], $row[ $j + 1 ] );
                }
                unset( $row );
            }
            $i = 0;
            $j = 0;
            while ( $i < $n && $j < $m ) {
                if ( $a[ $i ] === $b[ $j ] ) {
                    $mid[] = [ '=', $a[ $i ] ];
                    $i++;
                    $j++;
                } elseif ( $len[ $i + 1 ][ $j ] >= $len[ $i ][ $j + 1 ] ) {
                    $mid[] = [ '-', $a[ $i ] ];
                    $i++;
                } else {
                    $mid[] = [ '+', $b[ $j ] ];
                    $j++;
                }
            }
            for ( ; $i < $n; $i++ ) {
                $mid[] = [ '-', $a[ $i ] ];
            }
            for ( ; $j < $m; $j++ ) {
                $mid[] = [ '+', $b[ $j ] ];
            }
        }
        return array_merge( $head, $mid, $tail );
    }

    /**
     * Join neighbouring ops of one kind, and fold a lone whitespace run between
     * two changes into both sides, so "a b c" → "x y z" reads as one struck
     * phrase and one bold phrase rather than a stripe of single words.
     */
    private static function merge_ops( array $ops ): array {
        $count    = count( $ops );
        $expanded = [];
        foreach ( $ops as $k => $op ) {
            if ( '=' === $op[0] && $k > 0 && $k < $count - 1 && '' === trim( $op[1] ) && false === strpos( $op[1], "\n" )
                && '=' !== $ops[ $k - 1 ][0] && '=' !== $ops[ $k + 1 ][0] ) {
                $expanded[] = [ '-', $op[1] ];
                $expanded[] = [ '+', $op[1] ];
            } else {
                $expanded[] = $op;
            }
        }
        // Inside each run of changes, removals first, then additions.
        $out = [];
        $del = '';
        $ins = '';
        foreach ( $expanded as $op ) {
            if ( '-' === $op[0] ) {
                $del .= $op[1];
                continue;
            }
            if ( '+' === $op[0] ) {
                $ins .= $op[1];
                continue;
            }
            if ( '' !== $del ) {
                $out[] = [ '-', $del ];
            }
            if ( '' !== $ins ) {
                $out[] = [ '+', $ins ];
            }
            $del = '';
            $ins = '';
            if ( ! empty( $out ) && '=' === end( $out )[0] ) {
                $out[ count( $out ) - 1 ][1] .= $op[1];
            } else {
                $out[] = $op;
            }
        }
        if ( '' !== $del ) {
            $out[] = [ '-', $del ];
        }
        if ( '' !== $ins ) {
            $out[] = [ '+', $ins ];
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // AJAX (read only).
    // ------------------------------------------------------------------

    private static function guard( string $key ): void {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        if ( ! self::is_tracked( $key ) ) {
            wp_send_json_error( [ 'message' => __( 'This prompt has no history.', 'presshub-ai-editor' ) ], 400 );
        }
        if ( ! current_user_can( self::capability_for( $key ) ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to view this prompt history.', 'presshub-ai-editor' ) ], 403 );
        }
    }

    private static function request_key(): string {
        // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
        return isset( $_REQUEST['key'] ) && is_scalar( $_REQUEST['key'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['key'] ) ) : '';
    }

    private static function request_int( string $name ): int {
        // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
        return isset( $_REQUEST[ $name ] ) && is_scalar( $_REQUEST[ $name ] ) ? max( 0, (int) $_REQUEST[ $name ] ) : 0;
    }

    /** The version list of one prompt, for the history panel. */
    public static function list_payload( string $key ): array {
        $versions = self::versions( $key );
        $current  = self::current_text( $key );
        $hash     = '' !== $current ? md5( $current ) : '';
        $current_id = 0;
        foreach ( $versions as $v ) {
            if ( $v['content_hash'] === $hash ) {
                $current_id = $v['id'];
                break;
            }
        }
        $items = [];
        foreach ( $versions as $v ) {
            $items[] = [
                'id'         => $v['id'],
                'version'    => $v['version'],
                'created_at' => $v['created_at'],
                'user'       => self::user_name( $v['user_id'] ),
                'source'     => self::source_label( $v ),
                'chars'      => function_exists( 'mb_strlen' ) ? mb_strlen( $v['content'], 'UTF-8' ) : strlen( $v['content'] ),
                'uses'       => self::count_uses( $v['id'] ),
                'current'    => $v['id'] === $current_id,
                'records_url' => self::records_url( $v['id'] ),
            ];
        }
        return [
            'key'        => $key,
            'label'      => self::label( $key ),
            'current_id' => $current_id,
            'versions'   => $items,
        ];
    }

    /** One version's text and the generations it produced. */
    public static function version_payload( array $version ): array {
        $uses = [];
        if ( class_exists( 'PressHub_AI_Generation_Recorder' ) ) {
            foreach ( self::record_ids_for_version( $version['id'], self::USES_LIMIT ) as $record_id ) {
                $record = PressHub_AI_Generation_Recorder::get( $record_id );
                if ( null === $record ) {
                    continue;
                }
                $post_id = (int) $record['post_id'];
                $title   = '';
                $edit    = '';
                if ( $post_id > 0 && function_exists( 'get_the_title' ) ) {
                    $title = (string) get_the_title( $post_id );
                    $edit  = function_exists( 'get_edit_post_link' ) ? (string) get_edit_post_link( $post_id, 'raw' ) : '';
                }
                $uses[] = [
                    'record_id'  => $record_id,
                    'kind'       => class_exists( 'PressHub_AI_Generation_Inspector' ) ? PressHub_AI_Generation_Inspector::kind_label( (string) $record['kind'] ) : (string) $record['kind'],
                    'status'     => (string) $record['status'],
                    'created_at' => (string) $record['created_at'],
                    'post_id'    => $post_id,
                    'post_title' => '' !== $title ? $title : ( $post_id > 0 ? '#' . $post_id : '' ),
                    'post_url'   => $edit,
                    'record_url' => function_exists( 'add_query_arg' )
                        ? add_query_arg( [ 'page' => 'presshub-ai-generation-records', 'record_id' => $record_id ], admin_url( 'admin.php' ) )
                        : admin_url( 'admin.php?page=presshub-ai-generation-records&record_id=' . $record_id ),
                ];
            }
        }
        return [
            'id'          => $version['id'],
            'key'         => $version['prompt_key'],
            'version'     => $version['version'],
            'created_at'  => $version['created_at'],
            'user'        => self::user_name( $version['user_id'] ),
            'source'      => self::source_label( $version ),
            'content'     => $version['content'],
            'uses_count'  => self::count_uses( $version['id'] ),
            'uses'        => $uses,
            'records_url' => self::records_url( $version['id'] ),
        ];
    }

    public static function ajax_list(): void {
        $key = self::request_key();
        self::guard( $key );
        wp_send_json_success( self::list_payload( $key ) );
    }

    public static function ajax_get(): void {
        $version = self::get( self::request_int( 'id' ) );
        self::guard( null !== $version ? $version['prompt_key'] : '' );
        wp_send_json_success( self::version_payload( $version ) );
    }

    /**
     * Diff of version `from` against version `to`, or against `text` (what the
     * editor holds now, saved or not).
     */
    public static function ajax_diff(): void {
        $from = self::get( self::request_int( 'from' ) );
        self::guard( null !== $from ? $from['prompt_key'] : '' );
        $to_id = self::request_int( 'to' );
        if ( $to_id > 0 ) {
            $to = self::get( $to_id );
            if ( null === $to || $to['prompt_key'] !== $from['prompt_key'] ) {
                wp_send_json_error( [ 'message' => __( 'Pick two versions of the same prompt.', 'presshub-ai-editor' ) ], 400 );
            }
            $new_text  = $to['content'];
            /* translators: %d: version number */
            $new_label = sprintf( __( 'v%d', 'presshub-ai-editor' ), $to['version'] );
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification -- checked in guard().
            $raw       = isset( $_POST['text'] ) && is_string( $_POST['text'] ) ? (string) wp_unslash( $_POST['text'] ) : '';
            $new_text  = self::effective_text( $from['prompt_key'], $raw );
            $new_label = __( 'the text in the editor', 'presshub-ai-editor' );
        }
        $stats = self::diff_stats( $from['content'], $new_text );
        wp_send_json_success( [
            'html'     => self::diff_html( $from['content'], $new_text ),
            'added'    => $stats['added'],
            'removed'  => $stats['removed'],
            'same'     => $from['content'] === self::normalize( $new_text ),
            /* translators: 1: version number, 2: the other side ("v4" or "the text in the editor") */
            'heading'  => sprintf( __( 'Changes from v%1$d to %2$s', 'presshub-ai-editor' ), $from['version'], $new_label ),
        ] );
    }

    // ------------------------------------------------------------------
    // Settings page.
    // ------------------------------------------------------------------

    /** Script data for the history panel on the Settings page. */
    public static function script_data(): array {
        $prompts = [];
        foreach ( self::tracked() as $key => $entry ) {
            if ( function_exists( 'current_user_can' ) && ! current_user_can( self::capability_for( $key ) ) ) {
                continue;
            }
            $prompts[ $key ] = self::label( $key );
        }
        // phpcs:ignore WordPress.Security.NonceVerification -- navigation only.
        $open = isset( $_GET['prompt_history'] ) && is_scalar( $_GET['prompt_history'] ) ? sanitize_key( (string) $_GET['prompt_history'] ) : '';
        return [
            'ajax_url'     => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
            'prompts'      => $prompts,
            'open'         => isset( $prompts[ $open ] ) ? $open : '',
            // phpcs:ignore WordPress.Security.NonceVerification -- navigation only.
            'open_version' => isset( $_GET['prompt_version'] ) && is_scalar( $_GET['prompt_version'] ) ? max( 0, (int) $_GET['prompt_version'] ) : 0,
            'strings'      => [
                'button'        => __( 'History and changes', 'presshub-ai-editor' ),
                'title'         => __( 'Prompt history', 'presshub-ai-editor' ),
                'close'         => __( 'Close', 'presshub-ai-editor' ),
                'loading'       => __( 'Loading…', 'presshub-ai-editor' ),
                'empty'         => __( 'No versions kept yet. The next save of this prompt starts its history.', 'presshub-ai-editor' ),
                'version'       => __( 'Version', 'presshub-ai-editor' ),
                'saved'         => __( 'Saved', 'presshub-ai-editor' ),
                'by'            => __( 'By', 'presshub-ai-editor' ),
                'origin'        => __( 'Origin', 'presshub-ai-editor' ),
                'used'          => __( 'Generations', 'presshub-ai-editor' ),
                'actions'       => __( 'Actions', 'presshub-ai-editor' ),
                'current'       => __( 'in use', 'presshub-ai-editor' ),
                'view'          => __( 'View', 'presshub-ai-editor' ),
                'compare_prev'  => __( 'Changes from previous', 'presshub-ai-editor' ),
                'compare_edit'  => __( 'Compare with editor', 'presshub-ai-editor' ),
                'compare_pick'  => __( 'Compare two versions', 'presshub-ai-editor' ),
                'compare_from'  => __( 'From', 'presshub-ai-editor' ),
                'compare_to'    => __( 'To', 'presshub-ai-editor' ),
                'compare_go'    => __( 'Show changes', 'presshub-ai-editor' ),
                'restore'       => __( 'Load into editor', 'presshub-ai-editor' ),
                /* translators: %d: version number */
                'restored'      => __( 'Version %d is in the editor. Save the section to use it; the save is kept as a new version.', 'presshub-ai-editor' ),
                'no_changes'    => __( 'No changes: the two texts are the same.', 'presshub-ai-editor' ),
                /* translators: 1: words added, 2: words removed */
                'stats'         => __( '%1$d words added (bold), %2$d words removed (struck through).', 'presshub-ai-editor' ),
                'uses_heading'  => __( 'Generations that used this version', 'presshub-ai-editor' ),
                'uses_none'     => __( 'No generation has used this version yet.', 'presshub-ai-editor' ),
                'uses_all'      => __( 'All in Generation records', 'presshub-ai-editor' ),
                'record'        => __( 'Record', 'presshub-ai-editor' ),
                'error'         => __( 'The history could not be loaded.', 'presshub-ai-editor' ),
                'first'         => __( 'First version: nothing earlier to compare with.', 'presshub-ai-editor' ),
            ],
        ];
    }

    private static function log_failure( string $message, string $key ): void {
        try {
            if ( class_exists( 'PressHub_AI_Logger' ) ) {
                PressHub_AI_Logger::error( $message, [ 'prompt_key' => $key ] );
            }
        } catch ( \Throwable $t ) {
            // Logging must never break a save or a generation.
        }
    }
}
