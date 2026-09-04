<?php
/**
 * PressHub_AI_Briefing_Admin — WP Admin Dashboard for Daily Greek News Briefing & AI Podcast.
 *
 * Provides real-time pipeline monitoring, manual trigger actions, Cloudflare bot-block
 * manual document/notes upload panel, inline podcast dialogue script editor, and audio preview.
 *
 * @package PressHub_AI_Editor
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-news-harvester.php';
require_once __DIR__ . '/class-news-curator.php';
require_once __DIR__ . '/class-podcast-producer.php';
require_once __DIR__ . '/class-audio-synthesizer.php';
require_once __DIR__ . '/class-audit-logger.php';
require_once __DIR__ . '/class-settings-storage.php';

class PressHub_AI_Briefing_Admin {

    /** Admin script and style handles. */
    const SCRIPT_HANDLE = 'presshub-ai-briefing-admin-js';
    const STYLE_HANDLE  = 'presshub-ai-briefing-admin-css';

    /**
     * Retrieve all configured briefing news sources.
     *
     * @return array[] List of structured source arrays.
     */
    public static function get_news_sources(): array {
        return PressHub_AI_Settings_Storage::get_briefing_sources();
    }

    /**
     * Save (create or update) a news source and record an audit log event.
     *
     * @param array $data Source data array.
     * @return array Sanitized source array.
     */
    public static function save_news_source( array $data ): array {
        $sources = self::get_news_sources();

        $raw_url = trim( (string) ( $data['url'] ?? '' ) );
        $url     = esc_url_raw( $raw_url );
        $norm_url = strtolower( rtrim( $url, '/' ) );

        $id = sanitize_key( (string) ( $data['id'] ?? '' ) );
        if ( empty( $id ) ) {
            $id = 'src_' . substr( md5( $norm_url . microtime() ), 0, 8 );
        }

        $host      = (string) parse_url( $url, PHP_URL_HOST );
        $host_name = ucfirst( preg_replace( '/^www\./i', '', $host ) );
        $name      = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
        if ( '' === trim( $name ) ) {
            $name = $host_name ?: ( $url ?: __( 'News Source', 'presshub-ai-editor' ) );
        }

        $type = sanitize_key( (string) ( $data['type'] ?? 'text_news' ) );
        $supported_types = array_keys( PressHub_AI_Settings_Storage::get_supported_media_types() );
        if ( ! in_array( $type, $supported_types, true ) ) {
            $type = 'text_news';
        }

        $enabled = true;
        if ( isset( $data['enabled'] ) ) {
            $val = $data['enabled'];
            $enabled = ( true === $val || 1 === $val || '1' === $val || 'true' === $val );
        }

        $category = sanitize_text_field( (string) ( $data['category'] ?? 'General' ) );
        if ( '' === trim( $category ) ) {
            $category = 'General';
        }

        $notes = sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) );

        $max_articles = isset( $data['max_articles'] ) ? max( 1, min( 30, (int) $data['max_articles'] ) ) : 5;

        $clean = [
            'id'           => $id,
            'name'         => $name,
            'url'          => $url,
            'type'         => $type,
            'enabled'      => $enabled,
            'category'     => $category,
            'notes'        => $notes,
            'max_articles' => $max_articles,
        ];

        $existing_index  = null;
        $existing_source = null;
        foreach ( $sources as $index => $src ) {
            if ( ( $src['id'] ?? '' ) === $id ) {
                $existing_index  = $index;
                $existing_source = $src;
                break;
            }
        }

        $is_new = ( null === $existing_index );
        $is_toggle_only = false;

        if ( ! $is_new && is_array( $existing_source ) ) {
            $existing_copy = $existing_source;
            $clean_copy    = $clean;
            $existing_copy['enabled'] = $clean['enabled'];
            if ( $existing_copy === $clean_copy && $existing_source['enabled'] !== $clean['enabled'] ) {
                $is_toggle_only = true;
            }
        }

        if ( null !== $existing_index ) {
            $sources[ $existing_index ] = $clean;
        } else {
            $sources[] = $clean;
        }

        update_option( 'presshub_ai_briefing_sources', array_values( $sources ), false );

        // Audit Logging
        if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
            if ( $is_new ) {
                PressHub_AI_Audit_Logger::log(
                    'news_source_added',
                    'news_source',
                    $clean['id'],
                    [
                        'name'     => $clean['name'],
                        'url'      => $clean['url'],
                        'type'     => $clean['type'],
                        'enabled'  => $clean['enabled'],
                        'category' => $clean['category'],
                    ]
                );
            } elseif ( $is_toggle_only ) {
                PressHub_AI_Audit_Logger::log(
                    'news_source_toggled',
                    'news_source',
                    $clean['id'],
                    [
                        'name'             => $clean['name'],
                        'url'              => $clean['url'],
                        'enabled'          => $clean['enabled'],
                        'previous_enabled' => $existing_source['enabled'] ?? null,
                    ]
                );
            } else {
                PressHub_AI_Audit_Logger::log(
                    'news_source_updated',
                    'news_source',
                    $clean['id'],
                    [
                        'name'     => $clean['name'],
                        'url'      => $clean['url'],
                        'type'     => $clean['type'],
                        'enabled'  => $clean['enabled'],
                        'category' => $clean['category'],
                    ]
                );
            }
        }

        return $clean;
    }

    /**
     * Delete a news source by ID and record an audit log event.
     *
     * @param string $source_id Source ID.
     * @return bool True if deleted, false if not found.
     */
    public static function delete_news_source( string $source_id ): bool {
        $sources = self::get_news_sources();
        $found   = false;
        $deleted = null;
        $updated = [];

        foreach ( $sources as $src ) {
            if ( ( $src['id'] ?? '' ) === $source_id ) {
                $found   = true;
                $deleted = $src;
                continue;
            }
            $updated[] = $src;
        }

        if ( ! $found ) {
            return false;
        }

        update_option( 'presshub_ai_briefing_sources', array_values( $updated ), false );

        // Audit Logging
        if ( class_exists( 'PressHub_AI_Audit_Logger' ) ) {
            PressHub_AI_Audit_Logger::log(
                'news_source_deleted',
                'news_source',
                $source_id,
                [
                    'name' => $deleted['name'] ?? $source_id,
                    'url'  => $deleted['url'] ?? '',
                ]
            );
        }

        return true;
    }

    /**
     * Toggle a news source's enabled state and record an audit log event.
     *
     * @param string    $source_id Source ID.
     * @param bool|null $enabled   Explicit enabled state or null to flip.
     * @return array|null Updated source array or null if not found.
     */
    public static function toggle_news_source( string $source_id, ?bool $enabled = null ): ?array {
        $sources = self::get_news_sources();
        $target  = null;

        foreach ( $sources as $src ) {
            if ( ( $src['id'] ?? '' ) === $source_id ) {
                $target = $src;
                break;
            }
        }

        if ( null === $target ) {
            return null;
        }

        $new_enabled = ( null !== $enabled ) ? $enabled : ! ( ! empty( $target['enabled'] ) );
        $target['enabled'] = $new_enabled;

        return self::save_news_source( $target );
    }

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Capability required to access Daily Briefing Hub.
     *
     * @return string Required capability.
     */
    public function capability(): string {
        return (string) apply_filters( 'presshub_ai_briefing_cap', 'edit_posts' );
    }

    /**
     * Register Daily Briefing Hub as its own top-level operational menu.
     *
     * Previously this page was filed under Settings → Daily Briefing Hub, which
     * violated the UX convention that Settings hosts static configuration only.
     * The hub orchestrates harvest → curate → generate script → synthesize audio,
     * so it is promoted to a top-level sibling of Posts / Media (Issue #73).
     *
     * Layout:
     *   Daily Briefing Hub                  ← top-level entry (dashicons-microphone)
     *     └─ Daily Briefing Hub             ← same page, registered as its own
     *                                        first submenu so WP keeps the
     *                                        rendered link visible under its
     *                                        own parent in the sidebar.
     *
     * The PressHub AI settings page (options-general.php?page=presshub-ai) is
     * deliberately left untouched. Editors reach it via the existing
     * `curation_settings_url` deep-link exposed in the JS payload.
     *
     * Capability gating is preserved verbatim: the hub uses
     * {@see self::capability()} (filterable `presshub_ai_briefing_cap`,
     * default `edit_posts`).
     */
    public function add_admin_menu() {
        $cap      = $this->capability();
        $icon     = 'dashicons-microphone';
        $position = 26; // After Comments (25), before Appearance (60).

        // 1) Top-level entry.
        add_menu_page(
            __( 'PressHub AI — Daily Briefing Hub', 'presshub-ai-editor' ),
            __( 'Daily Briefing Hub', 'presshub-ai-editor' ),
            $cap,
            'presshub-ai-briefing-hub',
            [ $this, 'render_hub_page' ],
            $icon,
            $position
        );

        // 2) Same page registered explicitly as a submenu of itself so the
        //    rendered sidebar entry survives any future top-level reshuffling.
        add_submenu_page(
            'presshub-ai-briefing-hub',
            __( 'PressHub AI — Daily Briefing Hub', 'presshub-ai-editor' ),
            __( 'Daily Briefing Hub', 'presshub-ai-editor' ),
            $cap,
            'presshub-ai-briefing-hub',
            [ $this, 'render_hub_page' ]
        );
    }

    /**
     * Enqueue CSS and JS assets on the Daily Briefing Hub admin page.
     *
     * @param string $hook Admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( false === strpos( (string) $hook, 'presshub-ai-briefing-hub' ) ) {
            return;
        }

        wp_enqueue_style(
            self::STYLE_HANDLE,
            PRESSHUB_AI_URL . 'assets/briefing-admin.css',
            [],
            PRESSHUB_AI_VERSION
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            PRESSHUB_AI_URL . 'assets/briefing-admin.js',
            [ 'jquery', 'wp-i18n' ],
            PRESSHUB_AI_VERSION,
            true
        );

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'presshubBriefingAdmin',
            [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'presshub_ai_nonce' ),
                // Issue #83 — anchor "today" in the WP-local timezone used
                // by the token-log writer (class-token-logger.php uses
                // current_time('mysql')), so the JS bootstrap date matches
                // the server's notion of "today" instead of UTC. For any
                // operator east of UTC this prevents the Briefing Hub from
                // opening with yesterday's UTC date in the early morning.
                'date'     => function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
                // Issue #61 — expose the cap values to the JS so the
                // "Of N pool tokens, M were sent to the LLM" subtext
                // mirrors the curator's prompt-build math. Values come from
                // the Settings-First helpers so JS always reflects the
                // operator's current configuration.
                'cap_articles'          => PressHub_AI_Settings_Storage::get_curation_max_articles(),
                'cap_chars_per_article' => PressHub_AI_Settings_Storage::get_curation_max_chars_per_article(),
                'curation_settings_url' => admin_url( 'options-general.php?page=presshub-ai#briefing' ),
                'i18n'     => [
                    'harvesting'         => __( 'Συλλογή ειδήσεων σε εξέλιξη...', 'presshub-ai-editor' ),
                    'curating'           => __( 'Σύνταξη κειμένου ενημέρωσης...', 'presshub-ai-editor' ),
                    'generating_script'  => __( 'Δημιουργία διαλόγου podcast...', 'presshub-ai-editor' ),
                    'saving_script'      => __( 'Αποθήκευση σεναρίου...', 'presshub-ai-editor' ),
                    'synthesizing_audio' => __( 'Σύνθεση φωνών & podcast...', 'presshub-ai-editor' ),
                    'uploading'          => __( 'Επεξεργασία χειροκίνητων σημειώσεων...', 'presshub-ai-editor' ),
                    'success'            => __( 'Η λειτουργία ολοκληρώθηκε επιτυχώς!', 'presshub-ai-editor' ),
                    'error'              => __( 'Παρουσιάστηκε σφάλμα κατά την επεξεργασία.', 'presshub-ai-editor' ),
                    'confirm_harvest'    => __( 'Εκτέλεση ανάκτησης ειδήσεων τώρα; Θα σαρωθούν οι διαμορφωμένες πηγές.', 'presshub-ai-editor' ),
                    'confirm_synthesis'  => __( 'Εκτέλεση σύνθεσης ήχου podcast τώρα μέσω Google Cloud TTS;', 'presshub-ai-editor' ),
                    // Issue #65 — Bug B: localized "WP Status: %s" label used
                    // by updateUIFromStatus() to refresh the subtitle after a
                    // curation completes without a page reload.
                    'wp_status_label'    => __( 'WP Status: %s', 'presshub-ai-editor' ),
                    // Issue #79 — labels for the new stage-status boxes. The
                    // JS updateUIFromStatus() renderer uses these to compose
                    // timestamp chips, source chips, and audio stat pills.
                    'ts_attempted'       => __( 'Attempted: %s', 'presshub-ai-editor' ),
                    'ts_completed'       => __( 'Completed: %s', 'presshub-ai-editor' ),
                    'ts_duration_sec'    => __( 'Duration: %ss', 'presshub-ai-editor' ),
                    'ts_duration_hms'    => __( 'Duration: %s', 'presshub-ai-editor' ),
                    'stage_in_progress'  => __( 'In Progress', 'presshub-ai-editor' ),
                    'stage_completed'    => __( 'Completed', 'presshub-ai-editor' ),
                    'stage_failed'       => __( 'Failed', 'presshub-ai-editor' ),
                    'stage_pending'      => __( 'Awaiting previous stage', 'presshub-ai-editor' ),
                    'source_type_curated'=> __( 'All Harvested Articles (%d)', 'presshub-ai-editor' ),
                    'source_type_filter' => __( 'Selected Articles Filter (%d)', 'presshub-ai-editor' ),
                    'source_type_manual' => __( 'Manual Notes / Uploads', 'presshub-ai-editor' ),
                    'script_mode_curated'=> __( 'Curated Morning Briefing', 'presshub-ai-editor' ),
                    'script_mode_harvest'=> __( 'Direct Harvested Articles', 'presshub-ai-editor' ),
                    'audio_duration'     => __( '%s min', 'presshub-ai-editor' ),
                    'audio_filesize'     => __( '%s', 'presshub-ai-editor' ),
                    'audio_format'       => __( '%s / %s Hz', 'presshub-ai-editor' ),
                    'audio_engine_ms_gemini'    => __( 'Gemini Multi-Speaker', 'presshub-ai-editor' ),
                    'audio_engine_solo_gemini'  => __( 'Gemini Single-Voice', 'presshub-ai-editor' ),
                    'audio_engine_google_cloud' => __( 'Google Cloud TTS', 'presshub-ai-editor' ),
                    'audio_mode_topic'         => __( 'Topic-Stitched (%d topics)', 'presshub-ai-editor' ),
                    'audio_mode_single_pass'   => __( 'Single-Pass', 'presshub-ai-editor' ),
                    'voice_female_chip'  => __( '%s (Female)', 'presshub-ai-editor' ),
                    'voice_male_chip'    => __( '%s (Male)', 'presshub-ai-editor' ),
                    'voice_separator'    => __( ' + ', 'presshub-ai-editor' ),
                ],
            ]
        );
    }

    /**
     * Issue #79 — Collect per-stage execution lifecycle events from
     * `wp_presshub_ai_token_logs` for the canonical four action_triggers.
     *
     * Each returned stage array is composed from the most recent log row for
     * that action_trigger on the supplied date, plus optional second-most-recent
     * `started_at` evidence stashed in `metadata.started_at` by callers that
     * record the begin-of-call timestamp explicitly. Missing logs gracefully
     * yield empty arrays so the Briefing Hub stage cards never error.
     *
     * @param string $date Date string YYYY-MM-DD.
     * @return array{
     *   harvest:   array{attempted_at:string,completed_at:string,status:string,duration_ms:int,metric_units:int,sources_count:int,error_message:string},
     *   curation:  array{attempted_at:string,completed_at:string,status:string,duration_ms:int,error_message:string},
     *   script:    array{attempted_at:string,completed_at:string,status:string,duration_ms:int,error_message:string},
     *   audio:     array{attempted_at:string,completed_at:string,status:string,duration_ms:int,error_message:string}
     * } Per-stage execution event dictionary.
     */
    public static function collect_stage_execution_events( string $date ): array {
        $stages = [
            'harvest'  => 'scrape_harvest',
            'curation' => 'briefing_curation',
            'script'   => 'podcast_script',
            'audio'    => 'podcast_audio',
        ];

        $empty = [
            'attempted_at'   => '',
            'completed_at'   => '',
            'status'         => '',
            'duration_ms'    => 0,
            'metric_units'   => 0,
            'sources_count'  => 0,
            'error_message'  => '',
        ];

        $out = [];
        foreach ( $stages as $stage_key => $action_trigger ) {
            if ( ! class_exists( 'PressHub_AI_Token_Logger' ) ) {
                $out[ $stage_key ] = $empty;
                continue;
            }
            $logs = PressHub_AI_Token_Logger::get_logs( [
                'action'     => $action_trigger,
                'start_date' => $date,
                'end_date'   => $date,
                'orderby'    => 'id',
                'order'      => 'DESC',
                'per_page'   => 25, // bounded — one log row per stage attempt.
            ] );
            $items = is_array( $logs['items'] ?? null ) ? $logs['items'] : [];
            if ( empty( $items ) ) {
                $out[ $stage_key ] = $empty;
                continue;
            }

            // Prefer the most recent successful log; fall back to the most
            // recent log of any status. This mirrors a typical "what's the
            // last state of this stage" mental model.
            $row  = null;
            foreach ( $items as $candidate ) {
                if ( isset( $candidate['status'] ) && 'success' === $candidate['status'] ) {
                    $row = $candidate;
                    break;
                }
            }
            if ( null === $row ) {
                $row = $items[0];
            }

            $completed_at = (string) ( $row['created_at'] ?? '' );
            $duration_ms  = (int) ( $row['duration_ms'] ?? 0 );
            $started_at   = self::infer_stage_attempted_at( $row, $completed_at, $duration_ms );

            $meta = [];
            if ( ! empty( $row['metadata'] ) ) {
                $decoded = is_string( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : ( is_array( $row['metadata'] ) ? $row['metadata'] : [] );
                if ( is_array( $decoded ) ) {
                    $meta = $decoded;
                }
            }

            $sources_count = isset( $meta['sources_count'] ) ? (int) $meta['sources_count'] : 0;
            $error_message = isset( $row['error_message'] ) ? (string) $row['error_message'] : '';

            $out[ $stage_key ] = [
                'attempted_at'   => $started_at,
                'completed_at'   => $completed_at,
                'status'         => (string) ( $row['status'] ?? '' ),
                'duration_ms'    => $duration_ms,
                'metric_units'   => (int) ( $row['metric_units'] ?? 0 ),
                'sources_count'  => $sources_count,
                'error_message'  => $error_message,
            ];
        }

        return $out;
    }

    /**
     * Issue #79 — Compute the best-effort attempted_at timestamp for a stage
     * log row. Preference order:
     *   1. metadata.started_at (when callers explicitly record it).
     *   2. created_at − duration_ms (back-compat for older rows without metadata).
     *   3. created_at (when duration is unknown).
     *
     * @param array<string,mixed> $row
     * @param string              $completed_at
     * @param int                 $duration_ms
     */
    private static function infer_stage_attempted_at( array $row, string $completed_at, int $duration_ms ): string {
        if ( ! empty( $row['metadata'] ) ) {
            $decoded = is_string( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : ( is_array( $row['metadata'] ) ? $row['metadata'] : [] );
            if ( is_array( $decoded ) && ! empty( $decoded['started_at'] ) ) {
                return (string) $decoded['started_at'];
            }
        }
        if ( '' === $completed_at || $duration_ms <= 0 ) {
            return $completed_at;
        }
        $ts_completed = strtotime( $completed_at );
        if ( false === $ts_completed ) {
            return $completed_at;
        }
        // Issue #85 Tier 1: $completed_at is already in WP-local TZ (writers use
        // current_time('mysql')), so formatting the derived attempted timestamp
        // must also use WP-local TZ. date() avoids a redundant TZ conversion
        // round-trip that gmdate() would perform (and which caused Finding H:
        // chips showing wall-clock time shifted by the GMT offset).
        return date( 'Y-m-d H:i:s', $ts_completed - (int) round( $duration_ms / 1000 ) );
    }

    /**
     * Locate WordPress briefing post by date and type.
     *
     * @param string $date Date string YYYY-MM-DD.
     * @param string $type Briefing type ('text' or 'podcast').
     * @return object|null Post object or null.
     */
    public function find_briefing_post( string $date, string $type = 'text' ) {
        // Fast-path lookup for test environment stubs
        if ( ! empty( $GLOBALS['POST_META_STORE'] ) && is_array( $GLOBALS['POST_META_STORE'] ) ) {
            foreach ( $GLOBALS['POST_META_STORE'] as $post_id => $metas ) {
                if (
                    isset( $metas['_presshub_briefing_date'], $metas['_presshub_briefing_type'] )
                    && $metas['_presshub_briefing_date'] === $date
                    && $metas['_presshub_briefing_type'] === $type
                ) {
                    $post = get_post( $post_id );
                    if ( $post ) {
                        return $post;
                    }
                }
            }
        }

        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_query'     => [
                [ 'key' => '_presshub_briefing_date', 'value' => $date ],
                [ 'key' => '_presshub_briefing_type', 'value' => $type ],
            ],
        ] );

        return ! empty( $posts ) ? (object) $posts[0] : null;
    }

    /**
     * Get complete pipeline status for a specific date.
     *
     * @param string $date Date string YYYY-MM-DD (defaults to current date).
     * @return array Structured status dictionary.
     */
    public function get_briefing_status( string $date = '' ): array {
        if ( empty( $date ) ) {
            // Issue #83 — anchor "today" in the WP-local timezone used by
            // the token-log writer (class-token-logger.php writes
            // created_at via current_time('mysql')). For any operator
            // east of UTC this prevents the Briefing Hub from aggregating
            // yesterday's UTC-date stage events on the early morning of
            // a new local day.
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        $harvester = new PressHub_AI_News_Harvester();
        $producer  = new PressHub_AI_Podcast_Producer();

        // 1. Harvest Stage
        $snapshot = $harvester->load_snapshot( $date );
        $articles = $snapshot['articles'] ?? [];
        $blocked_sources = $snapshot['blocked_sources'] ?? [];
        $source_health = $snapshot['source_health'] ?? ( $snapshot['diagnostics'] ?? [] );
        $harvested_at = $snapshot['harvested_at'] ?? null;
        $is_harvested = ! empty( $snapshot ) && is_array( $snapshot );

        // 2. Text Story Stage
        $text_post = $this->find_briefing_post( $date, 'text' );
        $text_created = ( null !== $text_post && ! empty( $text_post->ID ) );
        $text_post_id = $text_created ? (int) $text_post->ID : null;
        $text_post_title = $text_created ? (string) ( $text_post->post_title ?? '' ) : '';
        $text_post_status = $text_created ? (string) ( $text_post->post_status ?? 'pending' ) : '';
        $text_edit_url = $text_created ? get_edit_post_link( $text_post_id ) : '';
        $text_permalink = $text_created ? get_permalink( $text_post_id ) : '';

        // 3. Podcast Dialogue Script Stage
        $script_text = $producer->get_script( $date ) ?? '';
        $script_created = ( '' !== trim( $script_text ) );
        $turns = $script_created ? $producer->parse_script_turns( $script_text ) : [];
        $word_count = $script_created
            ? (int) PressHub_AI_Context_Estimator::utf8_word_count( $script_text )
            : 0;

        // 4. Audio Podcast Stage
        $podcast_post = $this->find_briefing_post( $date, 'podcast' );
        $audio_created = ( null !== $podcast_post && ! empty( $podcast_post->ID ) );
        $podcast_post_id = $audio_created ? (int) $podcast_post->ID : null;
        $podcast_post_title = $audio_created ? (string) ( $podcast_post->post_title ?? '' ) : '';
        $podcast_post_status = $audio_created ? (string) ( $podcast_post->post_status ?? 'pending' ) : '';
        $podcast_edit_url = $audio_created ? get_edit_post_link( $podcast_post_id ) : '';
        $audio_url = $audio_created ? (string) get_post_meta( $podcast_post_id, '_presshub_audio_url', true ) : '';
        $audio_attachment_id = $audio_created ? (int) get_post_meta( $podcast_post_id, '_presshub_audio_attachment_id', true ) : null;

        // Format localized date
        $timestamp = strtotime( $date );
        $formatted_date = ( false !== $timestamp )
            ? ( function_exists( 'date_i18n' ) ? date_i18n( 'd/m/Y', $timestamp ) : date( 'd/m/Y', $timestamp ) )
            : $date;

        $pipeline_completed = ( $is_harvested && $text_created && $script_created && $audio_created );

        // Issue #65 — Settings-First masthead state surfaced to the JS
        // and integration tests. Values come from post meta recorded at
        // create_wordpress_post() time so the AJAX payload always
        // reflects what was actually applied to the latest post (not
        // the current Settings value, which may have since changed).
        $latest_post_meta_prefix = $text_created
            ? (string) get_post_meta( $text_post_id, '_presshub_text_title_prefix_applied', true )
            : '';
        $latest_post_meta_date_format = $text_created
            ? (string) get_post_meta( $text_post_id, '_presshub_text_title_date_format_applied', true )
            : '';

        // Issue #79 — surface per-stage execution lifecycle + post-meta context
        // so the JS can render the new stage status boxes without re-querying
        // token logs / post meta on every AJAX refresh. All four sub-keys are
        // safe to expose and never leak API keys (verified by integration
        // tests in BriefingStatusStageEventsTest).
        $stage_events = self::collect_stage_execution_events( $date );
        $text_source_context = $text_created
            ? ( class_exists( 'PressHub_AI_News_Curator' )
                ? PressHub_AI_News_Curator::get_source_context( (int) $text_post_id )
                : [ 'type' => '', 'count' => 0, 'preset' => '' ] )
            : [ 'type' => '', 'count' => 0, 'preset' => '' ];
        $script_meta = class_exists( 'PressHub_AI_Podcast_Producer' )
            ? ( new PressHub_AI_Podcast_Producer() )->get_script_meta( $date )
            : [ 'context_mode' => '', 'source_post_id' => 0, 'attempted_at' => '', 'completed_at' => '' ];
        $audio_meta  = ( $audio_created && class_exists( 'PressHub_AI_Audio_Synthesizer' ) )
            ? PressHub_AI_Audio_Synthesizer::get_audio_meta( (int) $podcast_post_id )
            : [
                'duration_sec'   => 0.0, 'filesize' => 0, 'sample_rate' => 0, 'format' => '',
                'engine' => '', 'female_voice' => '', 'male_voice' => '', 'tertiary_voice' => '',
                'host_count' => 0, 'split_by_topic' => 0, 'topic_count' => 0,
                'attempted_at' => '', 'completed_at' => '',
            ];

        // Issue #79 — compute harvest source labels for the Stage 1 chip row
        // ("Kathimerini, Philenews, ANT1 Live"). Falls back to hostname-only
        // labels when source hostnames can't be resolved (e.g. unit tests
        // that pass only article URLs).
        //
        // Bug fix (PR #82 review): the original implementation checked
        // `$blocked_source_labels` while iterating the active-sources loop,
        // but populated that array in a *separate* loop that ran after.
        // Because of that ordering, the `! in_array(...)` condition always
        // evaluated against an empty array, so any host present in both
        // `snapshot.sources` and `$blocked_sources` would render twice
        // (once as active, once as blocked). Reorder: resolve blocked
        // labels first, then resolve + dedupe + filter actives.
        $active_source_labels  = [];
        $blocked_source_labels = [];

        // 1. Resolve blocked source labels first so the active filter below
        //    has a fully-populated blocklist to compare against.
        foreach ( $blocked_sources as $blocked_url ) {
            $host = (string) parse_url( (string) $blocked_url, PHP_URL_HOST );
            if ( '' !== $host ) {
                $blocked_source_labels[] = ucfirst( preg_replace( '/^www\./i', '', $host ) );
            }
        }

        // 2. Resolve active sources, deduplicating across multiple articles
        //    from the same host and excluding any host that appears in the
        //    already-populated blocklist.
        foreach ( $snapshot['sources'] ?? [] as $src ) {
            $host  = (string) parse_url( (string) $src, PHP_URL_HOST );
            if ( '' === $host ) {
                continue;
            }
            $label = ucfirst( preg_replace( '/^www\./i', '', $host ) );
            if ( in_array( $label, $blocked_source_labels, true ) ) {
                continue;
            }
            if ( in_array( $label, $active_source_labels, true ) ) {
                continue;
            }
            $active_source_labels[] = $label;
        }

        $active_source_labels  = array_slice( $active_source_labels, 0, 5 );
        $blocked_source_labels = array_slice( $blocked_source_labels, 0, 5 );

        return [
            'date'                => $date,
            'formatted_date'      => $formatted_date,
            'harvested'           => $is_harvested,
            'harvested_at'        => $harvested_at,
            'article_count'       => count( $articles ),
            'sources'             => $snapshot['sources'] ?? [],
            'blocked_sources'     => $blocked_sources,
            'source_health'       => $source_health,
            'diagnostics'         => $source_health,
            'articles'            => $articles,
            'text_created'        => $text_created,
            'text_post_id'        => $text_post_id,
            'text_post_title'     => $text_post_title,
            'text_post_status'    => $text_post_status,
            'text_edit_url'       => $text_edit_url,
            'text_permalink'      => $text_permalink,
            // Issue #65 — Settings-First masthead state surfaced to the JS
            // and integration tests. Values come from post meta recorded at
            // create_wordpress_post() time so the AJAX payload always
            // reflects what was actually applied to the latest post (not
            // the current Settings value, which may have since changed).
            'text_title_prefix_applied'      => $latest_post_meta_prefix,
            'text_title_date_format_applied' => $latest_post_meta_date_format,
            'text_title_prefix_current'      => PressHub_AI_Settings_Storage::get_briefing_text_title_prefix(),
            'text_title_date_format_current' => PressHub_AI_Settings_Storage::get_briefing_text_title_date_format(),
            'script_created'      => $script_created,
            'script_text'         => $script_text,
            'script_turns_count'  => count( $turns ),
            'script_word_count'   => $word_count,
            'audio_created'       => $audio_created,
            'podcast_post_id'     => $podcast_post_id,
            'podcast_post_title'  => $podcast_post_title,
            'podcast_post_status' => $podcast_post_status,
            'podcast_edit_url'    => $podcast_edit_url,
            'audio_url'           => $audio_url,
            'audio_attachment_id' => $audio_attachment_id,
            'pipeline_completed'  => $pipeline_completed,
            // Issue #79 — per-stage execution lifecycle events consumed by
            // the JS updateUIFromStatus() renderer to populate the new
            // .presshub-stage-status-box containers on each milestone card.
            // Each sub-key (harvest, curation, script, audio) maps to the
            // canonical action_trigger name and follows the schema documented
            // in collect_stage_execution_events() below.
            'stage_events'        => $stage_events,
            // Issue #79 — source context + audio stats + script meta surfaced
            // directly so the JS does not have to re-resolve post-meta keys.
            'text_source_context' => $text_source_context,
            'script_meta'         => $script_meta,
            'audio_meta'          => $audio_meta,
            // Issue #79 — active/blocked source labels for Stage 1's chip row
            // (capped at 5 to keep the card compact).
            'active_source_labels'    => $active_source_labels,
            'blocked_source_labels'   => $blocked_source_labels,
            // Issue #79 — duration derived from completed_at − attempted_at so
            // the server-rendered HTML shows a stable wall-clock duration even
            // if the JS layer fails.
            'harvest_total_ms'    => (int) ( $stage_events['harvest']['duration_ms'] ?? 0 ),
            'curation_total_ms'   => (int) ( $stage_events['curation']['duration_ms'] ?? 0 ),
            'script_total_ms'     => (int) ( $stage_events['script']['duration_ms'] ?? 0 ),
            'audio_total_ms'      => (int) ( $stage_events['audio']['duration_ms'] ?? 0 ),
        ];
    }

    /**
     * Render the Daily Briefing Hub admin page.
     *
     * @param string $date Target date string (YYYY-MM-DD).
     */
    public function render_hub_page( string $date = '' ) {
        if ( ! current_user_can( $this->capability() ) ) {
            wp_die( __( 'You do not have permission to access the Daily Briefing Hub.', 'presshub-ai-editor' ) );
        }

        if ( empty( $date ) ) {
            $raw_date = isset( $_GET['briefing_date'] ) ? sanitize_text_field( wp_unslash( $_GET['briefing_date'] ) ) : '';
            // Issue #83 — anchor the page-default in the WP-local timezone
            // used by the token-log writer so the page does not open on
            // yesterday's UTC date for operators east of UTC.
            $date = ( '' !== $raw_date ) ? $raw_date : ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ) );
        }

        $status = $this->get_briefing_status( $date );
        $settings_url = admin_url( 'options-general.php?page=presshub-ai' );
        // Issue #61 — deep link into the Daily Briefing tab where the
        // curation cap knobs (Maximum Articles Sent to Curation LLM /
        // Maximum Characters per Article) live. The settings page uses
        // hash-based tabs, so the fragment activates the briefing pane.
        $curation_settings_url = admin_url( 'options-general.php?page=presshub-ai#briefing' );

        // ---------------------------------------------------------------------
        // Defensive PHP fallback for aggregate word/token totals (#57).
        // The milestone card and inspector toolbar pre-render placeholders
        // ("Total: 0 words" / "~0 tokens") in HTML; the JS recompute hook
        // (updateSelectedCountBadge on DOMContentLoaded) normally fills them
        // in. If JS is disabled, blocked, slow, or fails for any reason, we
        // still want the editor to see real numbers on first paint. Mirror
        // the JS computation here so server-side rendering matches client.
        // ---------------------------------------------------------------------
        $initial_words  = 0;
        $initial_tokens = 0;
        // Issue #61 — cap-aware server-side mirror. The LLM only ever sees
        // the first cap_articles articles, each truncated to cap_chars_per_article
        // characters, plus the per-block headers. We mirror the curator's
        // cap math here so the milestone card and the Inspector toolbar
        // show "Of N pool tokens, M were sent to the LLM" on first paint,
        // even before the JS recompute hook fires.
        $cap_articles_server          = PressHub_AI_Settings_Storage::get_curation_max_articles();
        $cap_chars_per_article_server = PressHub_AI_Settings_Storage::get_curation_max_chars_per_article();
        $capped_words  = 0;
        $capped_tokens = 0;
        $capped_articles_considered = 0;
        if ( ! empty( $status['articles'] ) && is_array( $status['articles'] ) ) {
            foreach ( $status['articles'] as $_art ) {
                $art_text = (string) ( $_art['content'] ?? '' );
                if ( '' === $art_text ) {
                    continue;
                }
                $initial_words  += (int) PressHub_AI_Context_Estimator::utf8_word_count( $art_text );
                $initial_tokens += (int) PressHub_AI_Context_Estimator::estimate_tokens( $art_text );

                if ( $capped_articles_considered >= $cap_articles_server ) {
                    continue;
                }
                $capped_articles_considered++;
                $capped_text = $art_text;
                if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
                    if ( mb_strlen( $capped_text ) > $cap_chars_per_article_server ) {
                        $capped_text = mb_substr( $capped_text, 0, $cap_chars_per_article_server );
                    }
                } elseif ( strlen( $capped_text ) > $cap_chars_per_article_server ) {
                    $capped_text = substr( $capped_text, 0, $cap_chars_per_article_server );
                }
                $capped_words  += (int) PressHub_AI_Context_Estimator::utf8_word_count( $capped_text );
                $capped_tokens += (int) PressHub_AI_Context_Estimator::estimate_tokens( $capped_text );
            }
        }
        $initial_words_label  = sprintf(
            /* translators: %s: localized total word count */
            __( 'Total: %s words', 'presshub-ai-editor' ),
            number_format_i18n( $initial_words )
        );
        $initial_tokens_label = sprintf(
            /* translators: %s: localized total token estimate */
            __( '~%s tokens', 'presshub-ai-editor' ),
            number_format_i18n( $initial_tokens )
        );
        // Issue #61 — "Of N pool tokens, M were sent to the LLM" subtext.
        // Omitted when the pool fits under the cap (no truncation occurred
        // and the comparison would just be "X → X"), to keep small snapshots
        // uncluttered.
        $llm_subtext_label = '';
        if ( $initial_tokens > 0 && $capped_tokens > 0 && $capped_tokens < $initial_tokens ) {
            $llm_subtext_label = sprintf(
                /* translators: 1: pool token estimate, 2: capped token estimate sent to LLM, 3: max articles, 4: max chars per article */
                __( 'Of %1$s pool tokens, %2$s were sent to the LLM for curation (cap: %3$d articles × %4$d chars/article).', 'presshub-ai-editor' ),
                number_format_i18n( $initial_tokens ),
                number_format_i18n( $capped_tokens ),
                (int) $cap_articles_server,
                (int) $cap_chars_per_article_server
            );
        }
        ?>
        <div class="wrap presshub-briefing-hub-wrap" id="presshub-briefing-hub-wrap" data-date="<?php echo esc_attr( $date ); ?>">
            <header class="presshub-hub-header">
                <div class="presshub-hub-title-group">
                    <h1 class="wp-heading-inline">
                        <?php echo esc_html__( 'PressHub AI — Daily News Briefing Hub', 'presshub-ai-editor' ); ?>
                    </h1>
                    <span class="presshub-date-badge">
                        📅 <?php echo esc_html( $status['formatted_date'] ); ?> (<code><?php echo esc_html( $date ); ?></code>)
                    </span>
                </div>
                <div class="presshub-hub-header-actions">
                    <label for="presshub-date-picker" class="screen-reader-text"><?php echo esc_html__( 'Select Date', 'presshub-ai-editor' ); ?></label>
                    <input type="date" id="presshub-date-picker" value="<?php echo esc_attr( $date ); ?>" class="presshub-date-input" />
                    <button type="button" class="button button-primary" id="btn-run-all">
                        ⚡ <?php echo esc_html__( 'Run Full Generation Now', 'presshub-ai-editor' ); ?>
                    </button>
                    <a href="<?php echo esc_url( $settings_url ); ?>" class="button button-secondary">
                        ⚙️ <?php echo esc_html__( 'Settings', 'presshub-ai-editor' ); ?>
                    </a>
                </div>
            </header>

            <div id="presshub-briefing-notices" class="presshub-notices-container" aria-live="polite"></div>

            <!-- Blocked Sources Alert Banner -->
            <div id="presshub-blocked-sources-alert" class="presshub-alert presshub-alert-warning" style="<?php echo empty( $status['blocked_sources'] ) ? 'display:none;' : ''; ?>">
                <div class="presshub-alert-icon">⚠️</div>
                <div class="presshub-alert-content">
                    <strong><?php echo esc_html__( 'Cloudflare / Anti-Bot Protection Detected', 'presshub-ai-editor' ); ?></strong>
                    <p>
                        <?php echo esc_html__( 'The following Greek news outlets were protected or blocked during the morning scrape:', 'presshub-ai-editor' ); ?>
                        <span id="blocked-sources-list">
                            <?php foreach ( $status['blocked_sources'] as $src ) : ?>
                                <span class="presshub-blocked-badge"><?php echo esc_html( $src ); ?></span>
                            <?php endforeach; ?>
                        </span>
                    </p>
                    <p class="presshub-alert-action-text">
                        <?php echo esc_html__( 'You can manually attach today\'s PDF or paste article text in the upload box below to complete the news pool.', 'presshub-ai-editor' ); ?>
                        <a href="#presshub-briefing-manual-upload" class="button button-small button-secondary"><?php echo esc_html__( 'Go to Manual Upload', 'presshub-ai-editor' ); ?></a>
                    </p>
                </div>
            </div>

                        <!-- Harvest Diagnostics & Source Health Section (Issue #5) -->
            <section class="presshub-section-container presshub-source-health-section" id="presshub-source-health-section">
                <div class="presshub-section-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <div class="presshub-section-title-wrap">
                        <h2>🩺 <?php echo esc_html__( 'Harvest Diagnostics & Source Health', 'presshub-ai-editor' ); ?></h2>
                        <p class="description">
                            <?php echo esc_html__( 'Per-source HTTP response codes, latency metrics, hybrid discovery method (RSS vs HTML Scraper), and article yields.', 'presshub-ai-editor' ); ?>
                        </p>
                    </div>
                    <div class="presshub-health-actions">
                        <button type="button" class="button button-secondary button-small" id="btn-refresh-diagnostics">
                            🔄 <?php echo esc_html__( 'Refresh Health Status', 'presshub-ai-editor' ); ?>
                        </button>
                    </div>
                </div>

                <div class="presshub-source-health-table-wrap" style="overflow-x: auto; margin-top: 12px;">
                    <table class="wp-list-table widefat fixed striped presshub-source-health-table" id="presshub-source-health-table">
                        <thead>
                            <tr>
                                <th style="width: 28%;"><?php echo esc_html__( 'News Source URL', 'presshub-ai-editor' ); ?></th>
                                <th style="width: 12%;"><?php echo esc_html__( 'Health Status', 'presshub-ai-editor' ); ?></th>
                                <th style="width: 10%;"><?php echo esc_html__( 'HTTP Code', 'presshub-ai-editor' ); ?></th>
                                <th style="width: 16%;"><?php echo esc_html__( 'Discovery Method', 'presshub-ai-editor' ); ?></th>
                                <th style="width: 10%;"><?php echo esc_html__( 'Latency', 'presshub-ai-editor' ); ?></th>
                                <th style="width: 12%;"><?php echo esc_html__( 'Yielded Articles', 'presshub-ai-editor' ); ?></th>
                                <th style="width: 12%;"><?php echo esc_html__( 'Notes / Cause', 'presshub-ai-editor' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="presshub-source-health-tbody">
                            <?php
                            $health_entries = ! empty( $status['source_health'] ) ? $status['source_health'] : [];
                            if ( ! empty( $health_entries ) ) :
                                foreach ( $health_entries as $h ) :
                                    $h_url    = $h['url'] ?? '';
                                    $h_status = $h['status'] ?? 'ok';
                                    $h_code   = (int) ( $h['http_code'] ?? 0 );
                                    $h_method = $h['discovery_method'] ?? 'UNKNOWN';
                                    $h_lat    = (int) ( $h['latency_ms'] ?? 0 );
                                    $h_count  = (int) ( $h['articles_yielded'] ?? 0 );
                                    $h_reason = $h['failure_reason'] ?? ( 'ok' === $h_status ? __( 'Healthy', 'presshub-ai-editor' ) : '-' );
                                    
                                    $badge_class = 'badge-success';
                                    if ( 'blocked' === $h_status ) $badge_class = 'badge-danger';
                                    elseif ( 'warning' === $h_status ) $badge_class = 'badge-warning';
                                    elseif ( 'error' === $h_status ) $badge_class = 'badge-danger';

                                    $method_class = 'method-html';
                                    if ( 'RSS_FEED' === $h_method || 'FEED_DIRECT' === $h_method ) $method_class = 'method-rss';
                            ?>
                                <tr>
                                    <td><code title="<?php echo esc_attr( $h_url ); ?>"><?php echo esc_html( $h_url ); ?></code></td>
                                    <td><span class="presshub-status-pill <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( strtoupper( $h_status ) ); ?></span></td>
                                    <td><strong><?php echo esc_html( $h_code ?: '-' ); ?></strong></td>
                                    <td><span class="presshub-method-pill <?php echo esc_attr( $method_class ); ?>"><?php echo esc_html( $h_method ); ?></span></td>
                                    <td><?php echo esc_html( $h_lat . 'ms' ); ?></td>
                                    <td><strong><?php echo esc_html( (string) $h_count ); ?></strong></td>
                                    <td><small><?php echo esc_html( $h_reason ); ?></small></td>
                                </tr>
                            <?php
                                endforeach;
                            else :
                            ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: #777; padding: 15px;">
                                        <?php echo esc_html__( 'No harvest diagnostics recorded yet for this date. Run scrape to populate source health.', 'presshub-ai-editor' ); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Pipeline Milestones 4-Stage Grid -->
            <div class="presshub-pipeline-grid">
                
                <!-- Stage 1: Harvesting -->
                <div class="presshub-card presshub-milestone-card <?php echo $status['harvested'] ? 'card-complete' : 'card-pending'; ?>" id="milestone-harvest">
                    <div class="presshub-card-header">
                        <div class="presshub-milestone-step">
                            <span class="step-num">1</span>
                            <h3><?php echo esc_html__( 'News Harvesting', 'presshub-ai-editor' ); ?></h3>
                        </div>
                        <span class="presshub-status-badge <?php echo $status['harvested'] ? 'badge-success' : 'badge-secondary'; ?>" id="status-badge-harvest">
                            <?php echo $status['harvested'] ? esc_html__( 'Harvested', 'presshub-ai-editor' ) : esc_html__( 'Pending Scrape', 'presshub-ai-editor' ); ?>
                        </span>
                    </div>
                    <div class="presshub-card-body">
                        <p class="card-metric">
                            <strong><span id="count-harvested-articles"><?php echo (int) $status['article_count']; ?></span></strong> <?php echo esc_html__( 'articles in daily pool', 'presshub-ai-editor' ); ?>
                        </p>
                        <?php if ( ! empty( $status['harvested_at'] ) ) : ?>
                            <p class="card-subtext"><?php echo sprintf( esc_html__( 'Scraped: %s', 'presshub-ai-editor' ), esc_html( $status['harvested_at'] ) ); ?></p>
                        <?php endif; ?>

                        <?php
                        // Issue #79 — pre-rendered Stage 1 status box. The JS
                        // layer refreshes these chips on every AJAX poll, but
                        // server-rendering them ensures the box is meaningful
                        // even when JS is disabled, slow, or has thrown an
                        // error.
                        $harv_evt       = (array) ( $status['stage_events']['harvest'] ?? [] );
                        $harv_attempted = trim( (string) ( $harv_evt['attempted_at'] ?? '' ) );
                        $harv_completed = trim( (string) ( $harv_evt['completed_at'] ?? '' ) );
                        $harv_status    = strtolower( (string) ( $harv_evt['status'] ?? '' ) );
                        $harv_duration  = (int) ( $status['harvest_total_ms'] ?? ( $harv_evt['duration_ms'] ?? 0 ) );
                        $harv_active    = (array) ( $status['active_source_labels'] ?? [] );
                        $harv_blocked   = (array) ( $status['blocked_source_labels'] ?? [] );
                        ?>
                        <div class="presshub-stage-status-box" id="stage-harvest-status-box" data-stage="harvest">
                            <?php if ( '' === $harv_attempted && '' === $harv_completed && 'error' !== $harv_status && empty( $harv_active ) && empty( $harv_blocked ) ) : ?>
                                <p class="description presshub-stage-empty">⏳ <?php echo esc_html__( 'Awaiting first run for this date.', 'presshub-ai-editor' ); ?></p>
                            <?php else : ?>
                            <div class="presshub-status-row">
                                <?php if ( '' !== $harv_attempted ) : ?>
                                    <span class="presshub-timestamp-chip" title="<?php echo esc_attr__( 'Pipeline stage start timestamp', 'presshub-ai-editor' ); ?>">
                                        ⏱ <?php echo esc_html( sprintf( __( 'Attempted: %s', 'presshub-ai-editor' ), $harv_attempted ) ); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( '' !== $harv_completed ) : ?>
                                    <span class="presshub-timestamp-chip" title="<?php echo esc_attr__( 'Pipeline stage completion timestamp', 'presshub-ai-editor' ); ?>">
                                        ✅ <?php echo esc_html( sprintf( __( 'Completed: %s', 'presshub-ai-editor' ), $harv_completed ) ); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( $harv_duration > 0 ) : ?>
                                    <span class="presshub-stat-pill">⏳ <?php
                                        $h = (int) floor( $harv_duration / 3600000 );
                                        $m = (int) floor( ( $harv_duration % 3600000 ) / 60000 );
                                        $s = (int) floor( ( $harv_duration % 60000 ) / 1000 );
                                        echo esc_html( sprintf( '%02d:%02d:%02d', $h, $m, $s ) );
                                    ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ( 'error' === $harv_status && ! empty( $harv_evt['error_message'] ) ) : ?>
                                <p class="presshub-stage-error description">⚠️ <?php echo esc_html( (string) $harv_evt['error_message'] ); ?></p>
                            <?php endif; ?>
                            <?php if ( ! empty( $harv_active ) || ! empty( $harv_blocked ) ) : ?>
                                <div class="presshub-status-row" style="margin-top: 4px;">
                                    <?php foreach ( $harv_active as $src_label ) : ?>
                                        <span class="presshub-source-tag">📰 <?php echo esc_html( $src_label ); ?></span>
                                    <?php endforeach; ?>
                                    <?php foreach ( $harv_blocked as $src_label ) : ?>
                                        <span class="presshub-source-tag presshub-source-blocked" title="<?php echo esc_attr__( 'Blocked / failed source', 'presshub-ai-editor' ); ?>">⛔ <?php echo esc_html( $src_label ); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="presshub-articles-summary-box" style="<?php echo empty( $status['articles'] ) ? 'display:none;' : ''; ?>">
                            <p style="margin: 8px 0; display: flex; flex-wrap: wrap; gap: 6px; align-items: center;">
                                <span id="presshub-selected-articles-count" class="presshub-selected-count-badge" style="font-size: 12px; display: inline-block;">
                                    <?php
                                    $total_articles = count( $status['articles'] );
                                    printf(
                                        /* translators: 1: selected count, 2: total count */
                                        esc_html__( 'Selected: %1$d / %2$d', 'presshub-ai-editor' ),
                                        $total_articles,
                                        $total_articles
                                    );
                                    ?>
                                </span>
                                <span id="presshub-selected-words-total" class="presshub-aggregate-totals presshub-aggregate-words-pill" style="font-size: 11px; display: inline-block;" aria-label="<?php echo esc_attr__( 'Aggregate word count for selected articles', 'presshub-ai-editor' ); ?>">
                                    <?php echo esc_html( $initial_words_label ); ?>
                                </span>
                                <span id="presshub-selected-tokens-total" class="presshub-aggregate-totals presshub-aggregate-tokens-pill" style="font-size: 11px; display: inline-block;" aria-label="<?php echo esc_attr__( 'Aggregate token estimate for selected articles', 'presshub-ai-editor' ); ?>">
                                    <?php echo esc_html( $initial_tokens_label ); ?>
                                </span>
                            </p>
                            <?php if ( '' !== $llm_subtext_label ) : ?>
                                <p id="presshub-milestone-llm-subtext" class="presshub-llm-subtext presshub-milestone-llm-subtext description" data-cap-articles="<?php echo esc_attr( (int) $cap_articles_server ); ?>" data-cap-chars="<?php echo esc_attr( (int) $cap_chars_per_article_server ); ?>" title="<?php echo esc_attr__( 'Pool tokens vs. tokens actually sent to the LLM (capped by max articles × max chars/article).', 'presshub-ai-editor' ); ?>">
                                    <?php echo esc_html( $llm_subtext_label ); ?>
                                    <a href="<?php echo esc_url( $curation_settings_url ); ?>" class="presshub-llm-subtext-link">
                                        <?php echo esc_html__( 'Configure cap in Settings → Daily Briefing.', 'presshub-ai-editor' ); ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                            <a href="#presshub-harvest-inspector-section" class="button button-primary button-small" id="btn-scroll-to-inspector" style="width: 100%; text-align: center; justify-content: center; display: inline-flex; align-items: center; gap: 4px; margin-top: 6px;">
                                🔍 <?php echo esc_html__( 'Inspect Articles & Text', 'presshub-ai-editor' ); ?>
                            </a>
                        </div>
                    </div>
                    <div class="presshub-card-footer">
                        <button type="button" class="button button-secondary" id="btn-run-scrape">
                            🔄 <?php echo $status['harvested'] ? esc_html__( 'Re-scrape Sources', 'presshub-ai-editor' ) : esc_html__( 'Run Scrape Now', 'presshub-ai-editor' ); ?>
                        </button>
                    </div>
                </div>

                <!-- Stage 2: Text Story Curation -->
                <div class="presshub-card presshub-milestone-card <?php echo $status['text_created'] ? 'card-complete' : 'card-pending'; ?>" id="milestone-curation">
                    <div class="presshub-card-header">
                        <div class="presshub-milestone-step">
                            <span class="step-num">2</span>
                            <h3><?php echo esc_html__( 'Text Story Curation', 'presshub-ai-editor' ); ?></h3>
                        </div>
                        <span class="presshub-status-badge <?php echo $status['text_created'] ? 'badge-success' : 'badge-secondary'; ?>" id="status-badge-curation">
                            <?php echo $status['text_created'] ? esc_html__( 'Story Created', 'presshub-ai-editor' ) : esc_html__( 'Pending Generation', 'presshub-ai-editor' ); ?>
                        </span>
                    </div>
                    <div class="presshub-card-body">
                        <?php
                        // Issue #79 — pre-rendered Stage 2 (curation) status box.
                        $cur_evt           = (array) ( $status['stage_events']['curation'] ?? [] );
                        $cur_attempted     = trim( (string) ( $cur_evt['attempted_at'] ?? '' ) );
                        $cur_completed     = trim( (string) ( $cur_evt['completed_at'] ?? '' ) );
                        $cur_status        = strtolower( (string) ( $cur_evt['status'] ?? '' ) );
                        $cur_duration_ms   = (int) ( $status['curation_total_ms'] ?? ( $cur_evt['duration_ms'] ?? 0 ) );
                        $tsc               = (array) ( $status['text_source_context'] ?? [] );
                        $tsc_type          = (string) ( $tsc['type'] ?? '' );
                        $tsc_count         = (int) ( $tsc['count'] ?? 0 );
                        $tsc_preset        = (string) ( $tsc['preset'] ?? '' );
                        ?>
                        <?php if ( $status['text_created'] ) : ?>
                            <p class="card-title-preview"><strong><?php echo esc_html( $status['text_post_title'] ); ?></strong></p>
                            <p class="card-subtext" id="presshub-text-post-status">
                                <?php
                                // Issue #65 — Bug B: the previous label "Status:"
                                // conflated the WP editorial publish status with
                                // the pipeline step status. "WP Status:" makes
                                // clear this is the post's position in the WP
                                // editorial-review queue (pending/draft/publish),
                                // not the pipeline step.
                                echo sprintf( esc_html__( 'WP Status: %s', 'presshub-ai-editor' ), '<code>' . esc_html( $status['text_post_status'] ) . '</code>' );
                                ?>
                            </p>
                        <?php else : ?>
                            <p class="card-empty-desc"><?php echo esc_html__( 'Synthesizes top Greek news stories into an editorial morning briefing post using selected Preset.', 'presshub-ai-editor' ); ?></p>
                        <?php endif; ?>

                        <div class="presshub-stage-status-box" id="stage-curation-status-box" data-stage="curation">
                            <?php if ( '' === $cur_attempted && '' === $cur_completed && 'error' !== $cur_status && '' === $tsc_type && '' === $tsc_preset && ! $status['text_created'] ) : ?>
                                <p class="description presshub-stage-empty">⏳ <?php echo esc_html__( 'Awaiting first run for this date.', 'presshub-ai-editor' ); ?></p>
                            <?php else : ?>
                            <div class="presshub-status-row">
                                <?php if ( '' !== $cur_attempted ) : ?>
                                    <span class="presshub-timestamp-chip">⏱ <?php echo esc_html( sprintf( __( 'Attempted: %s', 'presshub-ai-editor' ), $cur_attempted ) ); ?></span>
                                <?php endif; ?>
                                <?php if ( '' !== $cur_completed ) : ?>
                                    <span class="presshub-timestamp-chip">✅ <?php echo esc_html( sprintf( __( 'Completed: %s', 'presshub-ai-editor' ), $cur_completed ) ); ?></span>
                                <?php endif; ?>
                                <?php if ( $cur_duration_ms > 0 ) : ?>
                                    <span class="presshub-stat-pill">⏳ <?php echo esc_html( sprintf( '%01.1fs', $cur_duration_ms / 1000 ) ); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <div class="presshub-status-row" style="margin-top: 4px;">
                                <?php
                                $wp_status_label = ucfirst( (string) ( $status['text_post_status'] ?? '' ) );
                                $wp_status_cls   = 'badge-secondary';
                                if ( 'publish' === $status['text_post_status'] ) {
                                    $wp_status_cls = 'badge-success';
                                } elseif ( 'pending' === $status['text_post_status'] ) {
                                    $wp_status_cls = 'badge-warning';
                                } elseif ( 'trash' === $status['text_post_status'] ) {
                                    $wp_status_cls = 'badge-danger';
                                }
                                if ( '' !== $wp_status_label ) : ?>
                                    <span class="presshub-status-pill <?php echo esc_attr( $wp_status_cls ); ?>" id="presshub-curation-post-status-pill">
                                        📰 <?php echo esc_html( sprintf( __( 'Post #%1$d · %2$s', 'presshub-ai-editor' ), (int) $status['text_post_id'], $wp_status_label ) ); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( 'curated_briefing' === $tsc_type ) : ?>
                                    <span class="presshub-source-tag" title="<?php echo esc_attr__( 'Source material fed to the LLM', 'presshub-ai-editor' ); ?>">
                                        📦 <?php echo esc_html( sprintf( __( 'All Harvested Articles (%d)', 'presshub-ai-editor' ), max( 0, $tsc_count ) ) ); ?>
                                    </span>
                                <?php elseif ( 'harvested_articles' === $tsc_type ) : ?>
                                    <span class="presshub-source-tag" title="<?php echo esc_attr__( 'Source material fed to the LLM', 'presshub-ai-editor' ); ?>">
                                        🔎 <?php echo esc_html( sprintf( __( 'Selected Articles Filter (%d)', 'presshub-ai-editor' ), max( 0, $tsc_count ) ) ); ?>
                                    </span>
                                <?php elseif ( 'manual_notes' === $tsc_type ) : ?>
                                    <span class="presshub-source-tag" title="<?php echo esc_attr__( 'Source material fed to the LLM', 'presshub-ai-editor' ); ?>">
                                        📎 <?php echo esc_html__( 'Manual Notes / Uploads', 'presshub-ai-editor' ); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( '' !== $tsc_preset ) : ?>
                                    <span class="presshub-source-tag presshub-preset-tag" title="<?php echo esc_attr__( 'Author preset applied', 'presshub-ai-editor' ); ?>">
                                        🎯 <?php echo esc_html( $tsc_preset ); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ( 'error' === $cur_status && ! empty( $cur_evt['error_message'] ) ) : ?>
                                <p class="presshub-stage-error description">⚠️ <?php echo esc_html( (string) $cur_evt['error_message'] ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="presshub-card-footer">
                        <button type="button" class="button button-secondary" id="btn-run-curation">
                            ✍️ <?php echo $status['text_created'] ? esc_html__( 'Re-generate Story', 'presshub-ai-editor' ) : esc_html__( 'Generate Text Story', 'presshub-ai-editor' ); ?>
                        </button>
                        <?php if ( $status['text_created'] && ! empty( $status['text_edit_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $status['text_edit_url'] ); ?>" class="button button-link" target="_blank">
                                ↗️ <?php echo esc_html__( 'Edit Post', 'presshub-ai-editor' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Stage 3: Podcast Dialogue Script -->
                <div class="presshub-card presshub-milestone-card <?php echo $status['script_created'] ? 'card-complete' : 'card-pending'; ?>" id="milestone-script">
                    <div class="presshub-card-header">
                        <div class="presshub-milestone-step">
                            <span class="step-num">3</span>
                            <h3><?php echo esc_html__( 'Podcast Script', 'presshub-ai-editor' ); ?></h3>
                        </div>
                        <span class="presshub-status-badge <?php echo $status['script_created'] ? 'badge-success' : 'badge-secondary'; ?>" id="status-badge-script">
                            <?php echo $status['script_created'] ? esc_html__( 'Script Ready', 'presshub-ai-editor' ) : esc_html__( 'Pending Script', 'presshub-ai-editor' ); ?>
                        </span>
                    </div>
                    <div class="presshub-card-body">
                        <p class="card-metric">
                            <strong><span id="script-turns-count"><?php echo (int) $status['script_turns_count']; ?></span></strong> <?php echo esc_html__( 'turns', 'presshub-ai-editor' ); ?> | 
                            <strong><span id="script-words-count"><?php echo (int) $status['script_word_count']; ?></span></strong> <?php echo esc_html__( 'words', 'presshub-ai-editor' ); ?>
                        </p>
                        <p class="card-subtext"><?php echo esc_html__( 'Dual-host dialogue formatted with speaker tags for multi-voice synthesis.', 'presshub-ai-editor' ); ?></p>

                        <?php
                        // Issue #79 — pre-rendered Stage 3 (script) status box.
                        $sc_evt        = (array) ( $status['stage_events']['script'] ?? [] );
                        $sc_attempted  = trim( (string) ( $sc_evt['attempted_at'] ?? '' ) );
                        $sc_completed  = trim( (string) ( $sc_evt['completed_at'] ?? '' ) );
                        $sc_status     = strtolower( (string) ( $sc_evt['status'] ?? '' ) );
                        $sc_duration   = (int) ( $status['script_total_ms'] ?? ( $sc_evt['duration_ms'] ?? 0 ) );
                        $sc_meta       = (array) ( $status['script_meta'] ?? [] );
                        $sc_mode       = (string) ( $sc_meta['context_mode'] ?? '' );
                        $sc_source_post= (int) ( $sc_meta['source_post_id'] ?? 0 );
                        ?>
                        <div class="presshub-stage-status-box" id="stage-script-status-box" data-stage="script">
                            <?php if ( '' === $sc_attempted && '' === $sc_completed && 'error' !== $sc_status && '' === $sc_mode && ! $status['script_created'] ) : ?>
                                <p class="description presshub-stage-empty">⏳ <?php echo esc_html__( 'Awaiting first run for this date.', 'presshub-ai-editor' ); ?></p>
                            <?php else : ?>
                            <div class="presshub-status-row">
                                <?php if ( '' !== $sc_attempted ) : ?>
                                    <span class="presshub-timestamp-chip">⏱ <?php echo esc_html( sprintf( __( 'Attempted: %s', 'presshub-ai-editor' ), $sc_attempted ) ); ?></span>
                                <?php endif; ?>
                                <?php if ( '' !== $sc_completed ) : ?>
                                    <span class="presshub-timestamp-chip">✅ <?php echo esc_html( sprintf( __( 'Completed: %s', 'presshub-ai-editor' ), $sc_completed ) ); ?></span>
                                <?php endif; ?>
                                <?php if ( $sc_duration > 0 ) : ?>
                                    <span class="presshub-stat-pill">⏳ <?php echo esc_html( sprintf( '%01.1fs', $sc_duration / 1000 ) ); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <div class="presshub-status-row" style="margin-top: 4px;">
                                <?php if ( 'curated_briefing' === $sc_mode ) : ?>
                                    <span class="presshub-source-tag">📰 <?php echo esc_html__( 'Curated Morning Briefing', 'presshub-ai-editor' ); ?><?php if ( $sc_source_post > 0 ) : ?> · <code>#<?php echo (int) $sc_source_post; ?></code><?php endif; ?></span>
                                <?php elseif ( 'harvested_articles' === $sc_mode ) : ?>
                                    <span class="presshub-source-tag">🌐 <?php echo esc_html__( 'Direct Harvested Articles', 'presshub-ai-editor' ); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ( 'error' === $sc_status && ! empty( $sc_evt['error_message'] ) ) : ?>
                                <p class="presshub-stage-error description">⚠️ <?php echo esc_html( (string) $sc_evt['error_message'] ); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="presshub-context-mode-group">
                            <fieldset>
                                <legend class="screen-reader-text"><?php echo esc_html__( 'Podcast Context Source', 'presshub-ai-editor' ); ?></legend>
                                <label class="presshub-radio-label">
                                    <input type="radio" name="presshub_podcast_context_mode" value="curated_briefing" <?php echo ( $status['text_created'] || empty( $status['articles'] ) ) ? 'checked="checked"' : ''; ?>>
                                    <span><?php echo esc_html__( 'Χρήση Σημερινού Άρθρου Πρωινής Ενημέρωσης (Curated Briefing)', 'presshub-ai-editor' ); ?></span>
                                </label>
                                <label class="presshub-radio-label">
                                    <input type="radio" name="presshub_podcast_context_mode" value="harvested_articles" <?php echo ( ! $status['text_created'] && ! empty( $status['articles'] ) ) ? 'checked="checked"' : ''; ?>>
                                    <span><?php echo esc_html__( 'Χρήση Επιλεγμένων Άρθρων Ειδήσεων (Selected Articles)', 'presshub-ai-editor' ); ?></span>
                                </label>
                            </fieldset>
                        </div>
                    </div>
                    <div class="presshub-card-footer">
                        <button type="button" class="button button-secondary" id="btn-run-script">
                            🎙️ <?php echo $status['script_created'] ? esc_html__( 'Re-generate Script', 'presshub-ai-editor' ) : esc_html__( 'Generate Script', 'presshub-ai-editor' ); ?>
                        </button>
                    </div>
                </div>

                <!-- Stage 4: Multi-Voice Audio Podcast -->
                <div class="presshub-card presshub-milestone-card <?php echo $status['audio_created'] ? 'card-complete' : 'card-pending'; ?>" id="milestone-audio">
                    <div class="presshub-card-header">
                        <div class="presshub-milestone-step">
                            <span class="step-num">4</span>
                            <h3><?php echo esc_html__( 'Audio Podcast', 'presshub-ai-editor' ); ?></h3>
                        </div>
                        <span class="presshub-status-badge <?php echo $status['audio_created'] ? 'badge-success' : 'badge-secondary'; ?>" id="status-badge-audio">
                            <?php echo $status['audio_created'] ? esc_html__( 'Synthesized', 'presshub-ai-editor' ) : esc_html__( 'Pending Audio', 'presshub-ai-editor' ); ?>
                        </span>
                    </div>
                    <div class="presshub-card-body">
                        <?php if ( $status['audio_created'] && ! empty( $status['audio_url'] ) ) : ?>
                            <audio controls src="<?php echo esc_url( $status['audio_url'] ); ?>" class="presshub-audio-player" id="briefing-audio-player"></audio>
                            <p class="card-subtext"><?php echo esc_html( $status['podcast_post_title'] ); ?></p>
                        <?php else : ?>
                            <p class="card-empty-desc"><?php echo esc_html__( 'Stitches dual-voice Google Cloud TTS Greek audio and creates podcast post with native audio player.', 'presshub-ai-editor' ); ?></p>
                        <?php endif; ?>

                        <?php
                        // Issue #79 — pre-rendered Stage 4 (audio) status box.
                        $au_evt         = (array) ( $status['stage_events']['audio'] ?? [] );
                        $au_attempted   = trim( (string) ( $au_evt['attempted_at'] ?? '' ) );
                        $au_completed   = trim( (string) ( $au_evt['completed_at'] ?? '' ) );
                        $au_status      = strtolower( (string) ( $au_evt['status'] ?? '' ) );
                        $au_duration_ms = (int) ( $status['audio_total_ms'] ?? ( $au_evt['duration_ms'] ?? 0 ) );
                        $au             = (array) ( $status['audio_meta'] ?? [] );
                        $au_dur_sec     = (float) ( $au['duration_sec'] ?? 0 );
                        $au_filesize    = (int) ( $au['filesize'] ?? 0 );
                        $au_sample_rate = (int) ( $au['sample_rate'] ?? 0 );
                        $au_format      = strtoupper( (string) ( $au['format'] ?? '' ) );
                        $au_engine      = (string) ( $au['engine'] ?? '' );
                        $au_host_count  = (int) ( $au['host_count'] ?? ( $status['audio_meta']['host_count'] ?? 2 ) );
                        $au_fem         = (string) ( $au['female_voice'] ?? '' );
                        $au_mal         = (string) ( $au['male_voice'] ?? '' );
                        $au_ter         = (string) ( $au['tertiary_voice'] ?? '' );
                        $au_split       = (int) ( $au['split_by_topic'] ?? 0 );
                        $au_topics      = (int) ( $au['topic_count'] ?? 0 );
                        // Format helpers
                        $audio_min_secs = '';
                        if ( $au_dur_sec > 0 ) {
                            $audio_min_secs = sprintf( '%02d:%02d', (int) floor( $au_dur_sec / 60 ), (int) floor( $au_dur_sec ) % 60 );
                        } elseif ( $au_duration_ms > 0 ) {
                            $audio_min_secs = sprintf( '%02d:%02d', (int) floor( $au_duration_ms / 60000 ), (int) floor( ( $au_duration_ms / 1000 ) ) % 60 );
                        }
                        $audio_filesize_str = '';
                        if ( $au_filesize > 0 ) {
                            if ( $au_filesize >= 1048576 ) {
                                $audio_filesize_str = sprintf( '%.1f MB', $au_filesize / 1048576 );
                            } else {
                                $audio_filesize_str = sprintf( '%.0f KB', $au_filesize / 1024 );
                            }
                        }
                        $audio_engine_label = '';
                        if ( 'gemini' === $au_engine ) {
                            $audio_engine_label = 'Gemini Multi-Speaker';
                        } elseif ( 'google_cloud' === $au_engine || 'google_cloud_tts' === $au_engine ) {
                            $audio_engine_label = 'Google Cloud TTS';
                        }
                        $audio_mode_label = $au_split && $au_topics > 0
                            ? sprintf( 'Topic-Stitched (%d topics)', $au_topics )
                            : 'Single-Pass';
                        $voice_chips = [];
                        if ( $au_host_count >= 1 && '' !== $au_fem ) {
                            $voice_chips[] = sprintf( '%s (Presenter 1)', $au_fem );
                        }
                        if ( $au_host_count >= 2 && '' !== $au_mal ) {
                            $voice_chips[] = sprintf( '%s (Presenter 2)', $au_mal );
                        }
                        if ( $au_host_count >= 3 && '' !== $au_ter ) {
                            $voice_chips[] = sprintf( '%s (Presenter 3)', $au_ter );
                        }
                        ?>
                        <div class="presshub-stage-status-box" id="stage-audio-status-box" data-stage="audio">
                            <?php if ( '' === $au_attempted && '' === $au_completed && 'error' !== $au_status && empty( $voice_chips ) && empty( $audio_min_secs ) && empty( $audio_filesize_str ) && ! $status['audio_created'] ) : ?>
                                <p class="description presshub-stage-empty">⏳ <?php echo esc_html__( 'Awaiting first run for this date.', 'presshub-ai-editor' ); ?></p>
                            <?php else : ?>
                            <div class="presshub-status-row">
                                <?php if ( '' !== $au_attempted ) : ?>
                                    <span class="presshub-timestamp-chip">⏱ <?php echo esc_html( sprintf( __( 'Attempted: %s', 'presshub-ai-editor' ), $au_attempted ) ); ?></span>
                                <?php endif; ?>
                                <?php if ( '' !== $au_completed ) : ?>
                                    <span class="presshub-timestamp-chip">✅ <?php echo esc_html( sprintf( __( 'Completed: %s', 'presshub-ai-editor' ), $au_completed ) ); ?></span>
                                <?php endif; ?>
                                <?php if ( $au_duration_ms > 0 ) : ?>
                                    <span class="presshub-stat-pill">⏳ <?php
                                        $h = (int) floor( $au_duration_ms / 3600000 );
                                        $m = (int) floor( ( $au_duration_ms % 3600000 ) / 60000 );
                                        $s = (int) floor( ( $au_duration_ms % 60000 ) / 1000 );
                                        echo esc_html( sprintf( '%02d:%02d:%02d', $h, $m, $s ) );
                                    ?></span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <div class="presshub-status-row" style="margin-top: 4px; flex-wrap: wrap;">
                                <?php if ( '' !== $audio_min_secs ) : ?>
                                    <span class="presshub-stat-pill" title="<?php echo esc_attr__( 'Playback duration', 'presshub-ai-editor' ); ?>">⏱ <?php echo esc_html( sprintf( __( '%s min', 'presshub-ai-editor' ), $audio_min_secs ) ); ?></span>
                                <?php endif; ?>
                                <?php if ( '' !== $audio_filesize_str ) : ?>
                                    <span class="presshub-stat-pill" title="<?php echo esc_attr__( 'File size', 'presshub-ai-editor' ); ?>">📦 <?php echo esc_html( $audio_filesize_str ); ?></span>
                                <?php endif; ?>
                                <?php if ( '' !== $au_format && $au_sample_rate > 0 ) : ?>
                                    <span class="presshub-stat-pill" title="<?php echo esc_attr__( 'Audio format & sample rate', 'presshub-ai-editor' ); ?>">🎚 <?php echo esc_html( sprintf( __( '%1$s / %2$s Hz', 'presshub-ai-editor' ), $au_format, number_format_i18n( $au_sample_rate ) ) ); ?></span>
                                <?php endif; ?>
                                <?php if ( '' !== $audio_engine_label ) : ?>
                                    <span class="presshub-source-tag" title="<?php echo esc_attr__( 'Synthesis engine', 'presshub-ai-editor' ); ?>">⚙ <?php echo esc_html( $audio_engine_label ); ?></span>
                                <?php endif; ?>
                                <?php if ( '' !== $audio_mode_label ) : ?>
                                    <span class="presshub-source-tag" title="<?php echo esc_attr__( 'Generation mode', 'presshub-ai-editor' ); ?>">🧩 <?php echo esc_html( $audio_mode_label ); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if ( ! empty( $voice_chips ) ) : ?>
                                <div class="presshub-status-row" style="margin-top: 4px;">
                                    <?php foreach ( $voice_chips as $chip ) : ?>
                                        <span class="presshub-voice-chip">🎙 <?php echo esc_html( $chip ); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php
                            if ( $status['audio_created'] ) : ?>
                                <div class="presshub-status-row" style="margin-top: 4px;">
                                    <?php
                                    $pod_status_label = ucfirst( (string) ( $status['podcast_post_status'] ?? '' ) );
                                    $pod_status_cls   = 'badge-secondary';
                                    if ( 'publish' === $status['podcast_post_status'] ) {
                                        $pod_status_cls = 'badge-success';
                                    } elseif ( 'pending' === $status['podcast_post_status'] ) {
                                        $pod_status_cls = 'badge-warning';
                                    } elseif ( 'trash' === $status['podcast_post_status'] ) {
                                        $pod_status_cls = 'badge-danger';
                                    }
                                    ?>
                                    <span class="presshub-status-pill <?php echo esc_attr( $pod_status_cls ); ?>" id="presshub-podcast-post-status-pill">
                                        🎙 <?php echo esc_html( sprintf( __( 'Post #%1$d · %2$s', 'presshub-ai-editor' ), (int) $status['podcast_post_id'], $pod_status_label ) ); ?>
                                    </span>
                                    <?php if ( ! empty( $status['audio_attachment_id'] ) ) : ?>
                                        <span class="presshub-status-pill" id="presshub-podcast-attachment-pill">
                                            📎 <?php echo esc_html( sprintf( __( 'Attachment #%d', 'presshub-ai-editor' ), (int) $status['audio_attachment_id'] ) ); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if ( 'error' === $au_status && ! empty( $au_evt['error_message'] ) ) : ?>
                                <p class="presshub-stage-error description">⚠️ <?php echo esc_html( (string) $au_evt['error_message'] ); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="presshub-card-footer">
                        <button type="button" class="button button-primary" id="btn-synthesize-audio">
                            🔊 <?php echo $status['audio_created'] ? esc_html__( 'Re-synthesize Audio', 'presshub-ai-editor' ) : esc_html__( 'Synthesize Audio Podcast', 'presshub-ai-editor' ); ?>
                        </button>
                        <?php if ( $status['audio_created'] && ! empty( $status['podcast_edit_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $status['podcast_edit_url'] ); ?>" class="button button-link" target="_blank">
                                ↗️ <?php echo esc_html__( 'View Podcast', 'presshub-ai-editor' ); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- Full News Harvesting Article Inspector & Reading Workspace -->
            <section class="presshub-section-container presshub-harvest-inspector-section" id="presshub-harvest-inspector-section" style="<?php echo empty( $status['articles'] ) ? 'display:none;' : ''; ?>">
                <div class="presshub-section-header">
                    <div class="presshub-section-title-wrap">
                        <h2>📰 <?php echo esc_html__( 'Harvested News Pool & Article Content Inspector', 'presshub-ai-editor' ); ?></h2>
                        <p class="description">
                            <?php echo esc_html__( 'Inspect full downloaded article texts, search/filter by keyword or news source, and select which stories feed the morning briefing and podcast.', 'presshub-ai-editor' ); ?>
                        </p>
                    </div>
                </div>

                <div class="presshub-inspector-toolbar">
                    <div class="presshub-inspector-filters">
                        <div class="inspector-filter-group inspector-search">
                            <label for="presshub-article-search" class="screen-reader-text"><?php echo esc_html__( 'Search Articles', 'presshub-ai-editor' ); ?></label>
                            <input type="search" id="presshub-article-search" class="regular-text" placeholder="<?php echo esc_attr__( '🔍 Search headline, content, or source...', 'presshub-ai-editor' ); ?>" />
                        </div>
                        <div class="inspector-filter-group">
                            <label for="presshub-article-source-filter" class="screen-reader-text"><?php echo esc_html__( 'Filter by Source', 'presshub-ai-editor' ); ?></label>
                            <select id="presshub-article-source-filter">
                                <option value=""><?php echo esc_html__( 'All News Sources', 'presshub-ai-editor' ); ?></option>
                                <?php
                                if ( ! empty( $status['articles'] ) ) {
                                    $sources_list = array_unique( array_filter( array_column( $status['articles'], 'source' ) ) );
                                    sort( $sources_list );
                                    foreach ( $sources_list as $src_name ) {
                                        echo '<option value="' . esc_attr( $src_name ) . '">' . esc_html( $src_name ) . '</option>';
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="inspector-filter-group">
                            <label for="presshub-article-selection-filter" class="screen-reader-text"><?php echo esc_html__( 'Filter by Selection', 'presshub-ai-editor' ); ?></label>
                            <select id="presshub-article-selection-filter">
                                <option value="all"><?php echo esc_html__( 'All Articles', 'presshub-ai-editor' ); ?></option>
                                <option value="selected"><?php echo esc_html__( 'Selected Only', 'presshub-ai-editor' ); ?></option>
                                <option value="unselected"><?php echo esc_html__( 'Excluded Only', 'presshub-ai-editor' ); ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="presshub-inspector-actions">
                        <button type="button" class="button button-secondary button-small" id="btn-inspector-select-all">
                            <?php echo esc_html__( 'Select All', 'presshub-ai-editor' ); ?>
                        </button>
                        <button type="button" class="button button-secondary button-small" id="btn-inspector-deselect-all">
                            <?php echo esc_html__( 'Deselect All', 'presshub-ai-editor' ); ?>
                        </button>
                        <button type="button" class="button button-secondary button-small" id="btn-inspector-expand-all">
                            <?php echo esc_html__( 'Expand All Texts ▼', 'presshub-ai-editor' ); ?>
                        </button>
                        <button type="button" class="button button-secondary button-small" id="btn-inspector-collapse-all">
                            <?php echo esc_html__( 'Collapse All Texts ▲', 'presshub-ai-editor' ); ?>
                        </button>
                        <span id="presshub-inspector-count-badge" class="presshub-selected-count-badge">
                            <?php
                            $total_articles = count( $status['articles'] );
                            printf(
                                /* translators: 1: selected count, 2: total count */
                                esc_html__( 'Selected: %1$d / %2$d', 'presshub-ai-editor' ),
                                $total_articles,
                                $total_articles
                            );
                            ?>
                        </span>
                        <span id="presshub-inspector-words-total" class="presshub-aggregate-totals presshub-aggregate-words-pill" aria-label="<?php echo esc_attr__( 'Aggregate word count for selected articles', 'presshub-ai-editor' ); ?>">
                            <?php echo esc_html( $initial_words_label ); ?>
                        </span>
                        <span id="presshub-inspector-tokens-total" class="presshub-aggregate-totals presshub-aggregate-tokens-pill" aria-label="<?php echo esc_attr__( 'Aggregate token estimate for selected articles', 'presshub-ai-editor' ); ?>">
                            <?php echo esc_html( $initial_tokens_label ); ?>
                        </span>
                    </div>
                    <?php if ( '' !== $llm_subtext_label ) : ?>
                        <p id="presshub-inspector-llm-subtext" class="presshub-llm-subtext presshub-inspector-llm-subtext description" data-cap-articles="<?php echo esc_attr( (int) $cap_articles_server ); ?>" data-cap-chars="<?php echo esc_attr( (int) $cap_chars_per_article_server ); ?>" title="<?php echo esc_attr__( 'Pool tokens vs. tokens actually sent to the LLM (capped by max articles × max chars/article).', 'presshub-ai-editor' ); ?>">
                            <?php echo esc_html( $llm_subtext_label ); ?>
                            <a href="<?php echo esc_url( $curation_settings_url ); ?>" class="presshub-llm-subtext-link">
                                <?php echo esc_html__( 'Configure cap in Settings → Daily Briefing.', 'presshub-ai-editor' ); ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="presshub-inspector-articles-list" id="presshub-inspector-articles-list">
                    <?php if ( ! empty( $status['articles'] ) ) : ?>
                        <?php foreach ( $status['articles'] as $index => $article ) :
                            $art_title   = (string) ( $article['title'] ?? __( 'Untitled', 'presshub-ai-editor' ) );
                            $art_source  = (string) ( $article['source'] ?? __( 'Unknown', 'presshub-ai-editor' ) );
                            $art_url     = (string) ( $article['url'] ?? '' );
                            $art_content = (string) ( $article['content'] ?? '' );
                            $word_count  = (int) PressHub_AI_Context_Estimator::utf8_word_count( $art_content );
                            $char_count  = mb_strlen( $art_content );
                            $token_count = (int) PressHub_AI_Context_Estimator::estimate_tokens( $art_content );
                            // Issue #61 (S1) — also compute the per-article *capped* token
                            // estimate the LLM will see (truncated to the same
                            // cap_chars_per_article value the curator uses in
                            // format_articles_context()). The JS sums these
                            // for the first cap_articles selected cards so the
                            // "Of N pool tokens, M were sent to the LLM" subtext
                            // matches the server-rendered numbers exactly.
                            $capped_card_text = $art_content;
                            if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
                                if ( mb_strlen( $capped_card_text ) > $cap_chars_per_article_server ) {
                                    $capped_card_text = mb_substr( $capped_card_text, 0, $cap_chars_per_article_server );
                                }
                            } elseif ( strlen( $capped_card_text ) > $cap_chars_per_article_server ) {
                                $capped_card_text = substr( $capped_card_text, 0, $cap_chars_per_article_server );
                            }
                            $capped_token_count = (int) PressHub_AI_Context_Estimator::estimate_tokens( $capped_card_text );
                        ?>
                            <div class="presshub-inspector-card" data-index="<?php echo esc_attr( $index ); ?>" data-source="<?php echo esc_attr( strtolower( $art_source ) ); ?>" data-title="<?php echo esc_attr( strtolower( $art_title ) ); ?>" data-text="<?php echo esc_attr( strtolower( mb_substr( strip_tags( $art_content ), 0, 500 ) ) ); ?>" data-words="<?php echo esc_attr( $word_count ); ?>" data-tokens="<?php echo esc_attr( $token_count ); ?>" data-capped-tokens="<?php echo esc_attr( $capped_token_count ); ?>" data-cap-chars="<?php echo esc_attr( (int) $cap_chars_per_article_server ); ?>">
                                <div class="presshub-inspector-card-header">
                                    <div class="inspector-card-check">
                                        <input type="checkbox" class="presshub-article-checkbox" value="<?php echo esc_attr( $index ); ?>" checked="checked" id="inspector-check-<?php echo esc_attr( $index ); ?>" />
                                    </div>
                                    <div class="inspector-card-meta">
                                        <span class="presshub-article-source-pill"><?php echo esc_html( $art_source ); ?></span>
                                        <span class="presshub-article-words-pill"><?php echo sprintf( esc_html__( '%d words', 'presshub-ai-editor' ), $word_count ); ?></span>
                                        <span class="presshub-article-tokens-pill"><?php echo sprintf( esc_html__( '~%d tokens', 'presshub-ai-editor' ), $token_count ); ?></span>
                                    </div>
                                    <div class="inspector-card-title">
                                        <label for="inspector-check-<?php echo esc_attr( $index ); ?>">
                                            <strong><?php echo esc_html( $art_title ); ?></strong>
                                        </label>
                                        <?php if ( ! empty( $art_url ) ) : ?>
                                            <a href="<?php echo esc_url( $art_url ); ?>" target="_blank" rel="noopener noreferrer" class="presshub-article-external-link" title="<?php echo esc_attr__( 'Open original source article', 'presshub-ai-editor' ); ?>">↗</a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="inspector-card-toggle">
                                        <button type="button" class="button button-small presshub-toggle-text-btn" data-index="<?php echo esc_attr( $index ); ?>">
                                            📖 <span class="toggle-text-label"><?php echo esc_html__( 'Read Text ▼', 'presshub-ai-editor' ); ?></span>
                                        </button>
                                    </div>
                                </div>

                                <div class="presshub-inspector-card-drawer" id="inspector-drawer-<?php echo esc_attr( $index ); ?>" style="display: none;">
                                    <div class="inspector-drawer-meta-bar">
                                        <div class="drawer-stats">
                                            <span><strong><?php echo esc_html__( 'Length:', 'presshub-ai-editor' ); ?></strong> <?php echo sprintf( esc_html__( '%1$d words / %2$d characters', 'presshub-ai-editor' ), $word_count, $char_count ); ?></span>
                                            <?php if ( ! empty( $art_url ) ) : ?>
                                                <span class="drawer-url"><strong><?php echo esc_html__( 'URL:', 'presshub-ai-editor' ); ?></strong> <a href="<?php echo esc_url( $art_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $art_url ); ?></a></span>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="button button-small presshub-copy-article-btn" data-index="<?php echo esc_attr( $index ); ?>">
                                            📋 <?php echo esc_html__( 'Copy Text', 'presshub-ai-editor' ); ?>
                                        </button>
                                    </div>
                                    <div class="inspector-drawer-content" id="inspector-content-<?php echo esc_attr( $index ); ?>">
                                        <?php if ( ! empty( $art_content ) ) : ?>
                                            <?php echo function_exists( 'wpautop' ) ? wp_kses_post( wpautop( esc_html( $art_content ) ) ) : nl2br( esc_html( $art_content ) ); ?>
                                        <?php else : ?>
                                            <p class="description"><em><?php echo esc_html__( 'No body text extracted for this article snapshot.', 'presshub-ai-editor' ); ?></em></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p class="presshub-no-articles-msg" style="padding: 20px; text-align: center; color: #666;">
                            <?php echo esc_html__( 'No harvested articles found for today. Run news scraping above to populate the pool.', 'presshub-ai-editor' ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Interactive Script Editor Section -->
            <section class="presshub-section-container presshub-script-editor-section">
                <div class="presshub-section-header">
                    <h2>📝 <?php echo esc_html__( 'Interactive Podcast Script Editor', 'presshub-ai-editor' ); ?></h2>
                    <p class="description">
                        <?php echo esc_html__( 'Review and fine-tune speaker turns, adjust phonetic pronunciation for Greek proper nouns, or add custom remarks before audio synthesis.', 'presshub-ai-editor' ); ?>
                    </p>
                </div>
                <div class="presshub-script-editor-body">
                    <textarea id="presshub-briefing-script-editor" class="presshub-script-textarea" rows="18" placeholder="<?php echo esc_attr__( '[Μαρία]: Καλημέρα σε όλους!\n[Νίκος]: Καλημέρα Μαρία...', 'presshub-ai-editor' ); ?>"><?php echo esc_textarea( $status['script_text'] ); ?></textarea>
                    <div class="presshub-editor-actions">
                        <button type="button" class="button button-primary button-large" id="btn-save-script">
                            💾 <?php echo esc_html__( 'Save Script Changes', 'presshub-ai-editor' ); ?>
                        </button>
                        <span class="presshub-save-status" id="script-save-status"></span>
                    </div>
                </div>
            </section>

            <!-- Cloudflare Blocked Source Manual Upload Box -->
            <section class="presshub-section-container presshub-manual-upload-section" id="presshub-briefing-manual-upload">
                <div class="presshub-section-header">
                    <h2>📥 <?php echo esc_html__( 'Manual Document & Notes Upload (Cloudflare Fallback)', 'presshub-ai-editor' ); ?></h2>
                    <p class="description">
                        <?php echo esc_html__( 'If an outlet is protected by Cloudflare or anti-bot shields, upload PDF/DOCX files or paste article text notes here to merge into today\'s pool.', 'presshub-ai-editor' ); ?>
                    </p>
                </div>
                <form id="presshub-manual-upload-form" class="presshub-upload-form" enctype="multipart/form-data">
                    <div class="presshub-form-row">
                        <div class="presshub-form-col">
                            <label for="upload-source-outlet"><strong><?php echo esc_html__( 'Source / Outlet Name:', 'presshub-ai-editor' ); ?></strong></label>
                            <input type="text" id="upload-source-outlet" name="source" class="regular-text" placeholder="<?php echo esc_attr__( 'e.g. Kathimerini, Naftemporiki', 'presshub-ai-editor' ); ?>" />
                        </div>
                        <div class="presshub-form-col">
                            <label for="upload-article-title"><strong><?php echo esc_html__( 'Article Headline:', 'presshub-ai-editor' ); ?></strong></label>
                            <input type="text" id="upload-article-title" name="title" class="regular-text" placeholder="<?php echo esc_attr__( 'Main story headline', 'presshub-ai-editor' ); ?>" />
                        </div>
                    </div>
                    <div class="presshub-form-row">
                        <div class="presshub-form-col-full">
                            <label for="upload-article-content"><strong><?php echo esc_html__( 'Article Text / Editorial Notes:', 'presshub-ai-editor' ); ?></strong></label>
                            <textarea id="upload-article-content" name="content" rows="5" class="large-text" placeholder="<?php echo esc_attr__( 'Paste full article text, bullet points, or executive summary...', 'presshub-ai-editor' ); ?>"></textarea>
                        </div>
                    </div>
                    <div class="presshub-form-row">
                        <div class="presshub-form-col-full">
                            <label for="upload-files-input"><strong><?php echo esc_html__( 'Attach Files (PDF, DOCX, TXT):', 'presshub-ai-editor' ); ?></strong></label>
                            <input type="file" id="upload-files-input" name="files[]" multiple accept=".pdf,.docx,.txt" />
                        </div>
                    </div>
                    <div class="presshub-form-actions">
                        <button type="button" class="button button-secondary button-large" id="btn-process-upload">
                            📥 <?php echo esc_html__( 'Process & Merge Uploads', 'presshub-ai-editor' ); ?>
                        </button>
                        <span class="spinner" id="upload-spinner"></span>
                    </div>
                </form>
            </section>

        </div>
        <?php
    }
}
