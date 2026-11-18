<?php
/**
 * Settings clean-up (#237, settings overhaul phase 5).
 *
 * Removes the stored rows of settings that PressHub no longer reads, once,
 * on the first admin request after the upgrade:
 *
 *  1. Saves a copy of every setting (the #233 export format, without API keys
 *     or tokens) and keeps it downloadable from the clean-up report.
 *  2. Copies the second GitHub token into the AI GitHub token when that one is
 *     empty, so plugin updates keep their access (the one-release fallback).
 *  3. Deletes the removed rows and writes a report: PressHub → Usage & logs.
 *  4. Shows a notice once to the next administrator.
 *
 * For one release, reading a removed option fires _doing_it_wrong(), so code
 * outside PressHub that still reads one shows up in debug logs.
 *
 * @package presshub-ai-editor
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PressHub_Settings_Cleanup {

    /** Bump to run a new clean-up. */
    const VERSION = 1;

    const VERSION_OPTION  = 'presshub_settings_cleanup_version';
    const REPORT_OPTION   = 'presshub_settings_cleanup_report';
    const EXPORT_OPTION   = 'presshub_settings_cleanup_export';
    const NOTICE_OPTION   = 'presshub_settings_cleanup_notice';
    const DOWNLOAD_ACTION = 'presshub_settings_cleanup_export';
    const NONCE           = 'presshub_settings_cleanup_export';
    const ANCHOR          = 'presshub-cleanup-report';

    /** Release that removed the options, for _doing_it_wrong(). */
    const REMOVED_IN = '2.45.0';

    /**
     * Removed options => [ group, secret ]. Groups are the report rows.
     * Evidence for each is in docs/settings-inventory.md (#237).
     */
    const REMOVED = [
        'presshub_social_github_token'          => [ 'group' => 'github', 'secret' => true ],
        'presshub_social_twitter_api_key'       => [ 'group' => 'x_oauth1', 'secret' => true ],
        'presshub_social_twitter_api_secret'    => [ 'group' => 'x_oauth1', 'secret' => true ],
        'presshub_social_twitter_access_secret' => [ 'group' => 'x_oauth1', 'secret' => true ],
        'presshub_ai_briefing_podcast_prompt_1' => [ 'group' => 'podcast_prompts', 'secret' => false ],
        'presshub_ai_briefing_podcast_prompt_2' => [ 'group' => 'podcast_prompts', 'secret' => false ],
        'presshub_ai_briefing_podcast_prompt_3' => [ 'group' => 'podcast_prompts', 'secret' => false ],
    ];

    /** Plugin updates read this token; the second one is copied here if it is empty. */
    const GITHUB_TOKEN = 'presshub_ai_github_token';

    /** True while run() reads the removed options itself. */
    private static $running = false;

    /** Options already reported by _doing_it_wrong() in this request. */
    private static $warned = [];

    public static function register_hooks(): void {
        // After PressHub registers its settings (admin_init, priority 10), so
        // the export does not register them a second time.
        add_action( 'admin_init', [ __CLASS__, 'maybe_run' ], 20 );
        add_action( 'admin_notices', [ __CLASS__, 'render_notice' ] );
        add_action( 'admin_post_' . self::DOWNLOAD_ACTION, [ __CLASS__, 'handle_download' ] );
        foreach ( array_keys( self::REMOVED ) as $key ) {
            add_filter( 'pre_option_' . $key, [ __CLASS__, 'warn_removed_read' ], 10, 2 );
        }
    }

    /** Report rows: label, why it went, and what happened to it. */
    public static function groups(): array {
        return [
            'github'          => [
                'label'  => __( 'Second GitHub token', 'presshub-ai-editor' ),
                'reason' => __( 'Plugin updates use the AI GitHub token.', 'presshub-ai-editor' ),
            ],
            'x_oauth1'        => [
                'label'  => __( 'X OAuth1 keys (3)', 'presshub-ai-editor' ),
                'reason' => __( 'Never read. Posting to X uses the bearer token, the access token or the worker.', 'presshub-ai-editor' ),
            ],
            'podcast_prompts' => [
                'label'  => __( 'Podcast prompts 1–3', 'presshub-ai-editor' ),
                'reason' => __( 'Replaced by the per-style prompt studio.', 'presshub-ai-editor' ),
            ],
        ];
    }

    /** @return string[] */
    public static function removed_keys(): array {
        return array_keys( self::REMOVED );
    }

    public static function is_removed( string $key ): bool {
        return isset( self::REMOVED[ $key ] );
    }

    public static function maybe_run(): void {
        if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
            return;
        }
        if ( ! class_exists( 'PressHub_Settings_Transfer' ) || ! class_exists( 'PressHub_Settings_Registry' ) ) {
            return;
        }
        self::run();
    }

    /**
     * The clean-up itself. Returns the report it saved.
     *
     * @return array{time:int,version:string,removed:array<string,bool>,copied:string[],export_saved:bool}
     */
    public static function run(): array {
        self::$running = true;
        try {
            $missing = new stdClass();
            $stored  = [];
            foreach ( self::REMOVED as $key => $meta ) {
                $value = get_option( $key, $missing );
                if ( $value !== $missing ) {
                    $stored[ $key ] = $value;
                }
            }

            // 1. A copy of every setting first. The removed options are no
            // longer registered, so add the ones that are not secrets.
            $export = PressHub_Settings_Transfer::export_data();
            foreach ( $stored as $key => $value ) {
                if ( empty( self::REMOVED[ $key ]['secret'] ) ) {
                    $export['options'][ $key ] = $value;
                } else {
                    $export['secrets_left_out'][] = $key;
                }
            }
            $export['note'] = 'Saved by PressHub before the settings clean-up (#237).';
            $json           = function_exists( 'wp_json_encode' )
                ? wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
                : json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            $saved = is_string( $json ) && '' !== $json && update_option( self::EXPORT_OPTION, $json, false );
            if ( ! $saved && get_option( self::EXPORT_OPTION ) !== $json ) {
                // Never delete without the copy; try again on the next request.
                return [];
            }

            // 2. Keep plugin updates working: the second GitHub token is only
            // copied when the one updates read is empty.
            $copied = [];
            $social = trim( (string) ( $stored['presshub_social_github_token'] ?? '' ) );
            if ( '' !== $social && '' === trim( (string) get_option( self::GITHUB_TOKEN, '' ) ) ) {
                update_option( self::GITHUB_TOKEN, $social );
                $copied[] = 'presshub_social_github_token';
            }

            // 3. Delete the rows.
            $removed = [];
            foreach ( self::removed_keys() as $key ) {
                $removed[ $key ] = array_key_exists( $key, $stored );
                if ( $removed[ $key ] ) {
                    delete_option( $key );
                }
            }

            $report = [
                'time'         => time(),
                'version'      => defined( 'PRESSHUB_AI_VERSION' ) ? (string) PRESSHUB_AI_VERSION : '',
                'removed'      => $removed,
                'copied'       => $copied,
                'export_saved' => true,
            ];
            update_option( self::REPORT_OPTION, $report, false );
            update_option( self::NOTICE_OPTION, count( array_filter( $removed ) ) > 0 ? 1 : 0, false );
            update_option( self::VERSION_OPTION, self::VERSION, false );
            return $report;
        } finally {
            self::$running = false;
        }
    }

    /** pre_option_{removed} filter: a removed option was read. */
    public static function warn_removed_read( $pre, $option = '' ) {
        $option = (string) $option;
        if ( ! self::$running && '' !== $option && ! isset( self::$warned[ $option ] ) && function_exists( '_doing_it_wrong' ) ) {
            self::$warned[ $option ] = true;
            _doing_it_wrong(
                'get_option',
                sprintf( 'PressHub removed the %s option in %s; it is no longer saved or read.', esc_html( $option ), self::REMOVED_IN ),
                self::REMOVED_IN
            );
        }
        return $pre;
    }

    /** Test helper. */
    public static function reset_warnings(): void {
        self::$warned = [];
    }

    // ------------------------------------------------------------------
    // Report, notice, download.
    // ------------------------------------------------------------------

    public static function report(): ?array {
        $report = get_option( self::REPORT_OPTION, null );
        return is_array( $report ) && isset( $report['removed'] ) ? $report : null;
    }

    /** Number of removed settings that had a saved value. */
    public static function removed_count( ?array $report = null ): int {
        $report = $report ?? self::report();
        return $report ? count( array_filter( (array) $report['removed'] ) ) : 0;
    }

    public static function report_url(): string {
        $slug = class_exists( 'PressHub_Admin_Menu' ) ? PressHub_Admin_Menu::USAGE_SLUG : 'presshub-ai-usage';
        return admin_url( 'admin.php?page=' . $slug ) . '#' . self::ANCHOR;
    }

    public static function download_url(): string {
        $url = admin_url( 'admin-post.php?action=' . self::DOWNLOAD_ACTION );
        return function_exists( 'wp_nonce_url' ) ? wp_nonce_url( $url, self::NONCE ) : $url;
    }

    /** Shown once, to the first administrator who opens wp-admin after the clean-up. */
    public static function render_notice(): void {
        if ( ! (int) get_option( self::NOTICE_OPTION, 0 ) || ! PressHub_Capabilities::current_user_can_manage() ) {
            return;
        }
        update_option( self::NOTICE_OPTION, 0, false );
        $count = self::removed_count();
        echo '<div class="notice notice-success is-dismissible presshub-cleanup-notice"><p><strong>';
        /* translators: %d: number of removed settings */
        echo esc_html( sprintf( _n( 'PressHub cleaned up its settings: %d unused setting was removed.', 'PressHub cleaned up its settings: %d unused settings were removed.', $count, 'presshub-ai-editor' ), $count ) );
        echo '</strong> ' . esc_html__( 'A copy of all settings was saved first. Nothing you use changed.', 'presshub-ai-editor' ) . '</p><p>';
        echo '<a class="button" href="' . esc_url( self::report_url() ) . '">' . esc_html__( 'See the report', 'presshub-ai-editor' ) . '</a>';
        echo '</p></div>';
    }

    /** PressHub → Usage & logs → Clean-up report. Prints nothing before a clean-up ran. */
    public static function render_report(): void {
        $report = self::report();
        if ( ! $report ) {
            return;
        }
        $removed = (array) $report['removed'];
        $copied  = (array) ( $report['copied'] ?? [] );
        $when    = function_exists( 'wp_date' ) ? wp_date( 'j M Y, H:i', (int) $report['time'] ) : gmdate( 'j M Y, H:i', (int) $report['time'] );
        echo '<section class="presshub-cleanup-report" id="' . esc_attr( self::ANCHOR ) . '" aria-labelledby="' . esc_attr( self::ANCHOR ) . '-title">';
        /* translators: %s: date and time of the clean-up */
        echo '<h2 id="' . esc_attr( self::ANCHOR ) . '-title">' . esc_html( sprintf( __( 'Clean-up report (%s)', 'presshub-ai-editor' ), $when ) ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'PressHub no longer reads these settings, so their saved values were deleted. A copy of all settings was saved first.', 'presshub-ai-editor' ) . '</p>';
        echo '<table class="widefat striped presshub-cleanup-table"><thead><tr>';
        echo '<th scope="col">' . esc_html__( 'Setting', 'presshub-ai-editor' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'What happened', 'presshub-ai-editor' ) . '</th>';
        echo '<th scope="col">' . esc_html__( 'Why', 'presshub-ai-editor' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( self::groups() as $group => $row ) {
            $keys = array_keys( array_filter( self::REMOVED, static function ( $meta ) use ( $group ) {
                return $meta['group'] === $group;
            } ) );
            $had = array_values( array_filter( $keys, static function ( $k ) use ( $removed ) {
                return ! empty( $removed[ $k ] );
            } ) );
            if ( array_intersect( $keys, $copied ) ) {
                $result = __( 'Copied to the GitHub token in Connections, then removed', 'presshub-ai-editor' );
            } elseif ( $had ) {
                $result = __( 'Removed', 'presshub-ai-editor' );
            } else {
                $result = __( 'Nothing saved; nothing to remove', 'presshub-ai-editor' );
            }
            echo '<tr><td>' . esc_html( $row['label'] ) . '<details class="presshub-cleanup-details"><summary>' . esc_html__( 'Details', 'presshub-ai-editor' ) . '</summary><ul>';
            foreach ( $keys as $k ) {
                $state = in_array( $k, $had, true ) ? __( 'saved value deleted', 'presshub-ai-editor' ) : __( 'was not saved', 'presshub-ai-editor' );
                echo '<li><code>' . esc_html( $k ) . '</code> ' . esc_html( $state ) . '</li>';
            }
            echo '</ul></details></td><td>' . esc_html( $result ) . '</td><td>' . esc_html( $row['reason'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ( ! empty( $report['export_saved'] ) && get_option( self::EXPORT_OPTION ) ) {
            echo '<p><a class="button" href="' . esc_url( self::download_url() ) . '" download>' . esc_html__( 'Download the pre-clean-up copy (JSON, no keys)', 'presshub-ai-editor' ) . '</a> ';
            echo '<span class="description">' . esc_html__( 'Import it under Settings → Site & system → Diagnostics to restore a value.', 'presshub-ai-editor' ) . '</span></p>';
        }
        echo '</section>';
    }

    public static function handle_download(): void {
        if ( ! PressHub_Capabilities::current_user_can_manage() ) {
            wp_die( esc_html__( 'You are not allowed to download PressHub settings.', 'presshub-ai-editor' ), 403 );
        }
        check_admin_referer( self::NONCE );
        $json = (string) get_option( self::EXPORT_OPTION, '' );
        if ( '' === $json ) {
            wp_die( esc_html__( 'There is no pre-clean-up copy on this site.', 'presshub-ai-editor' ), 404 );
        }
        $report = self::report();
        $name   = 'presshub-settings-before-clean-up-' . gmdate( 'Y-m-d', (int) ( $report['time'] ?? time() ) ) . '.json';
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $name . '"' );
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
        exit;
    }
}
