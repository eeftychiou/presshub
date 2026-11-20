<?php
/**
 * #309 (E4): every editor or action that changes a setting or a settings or
 * editorial store writes an audit event (rule 3.4), never with a secret value.
 *
 *  1. Source guard: each writer's function body calls PressHub_AI_Audit_Logger::log()
 *     with its event name.
 *  2. Behaviour: the preset stores, the editorial agenda and the people-fiche
 *     editor write their event through the real code.
 *  3. The worker secret event carries only the masked secret.
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/wp-action-wrapper.php';
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) { return 'http://example.test/wp-content/plugins/presshub-ai-editor/'; }
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( $file, $callback ) { $GLOBALS['DEACTIVATION_HOOKS'][] = $callback; }
}
defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' ) || define( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER', true );
require_once dirname( __DIR__ ) . '/presshub-ai-editor.php';

$failures = [];
$check    = static function ( bool $ok, string $what ) use ( &$failures ): void {
    if ( ! $ok ) {
        $failures[] = $what;
    }
};

/** The body of a method, from its signature to the next method at the same indent. */
$body_of = static function ( string $file, string $method ): string {
    $src = str_replace( "\r\n", "\n", (string) file_get_contents( dirname( __DIR__ ) . '/includes/' . $file ) );
    if ( ! preg_match( '/function ' . preg_quote( $method, '/' ) . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
        return '';
    }
    $start = $m[0][1];
    $next  = preg_match( '/\n    (?:public|private|protected)(?: static)? function /', $src, $n, PREG_OFFSET_CAPTURE, $start + 10 ) ? $n[0][1] : strlen( $src );
    return substr( $src, $start, $next - $start );
};

// 1. Source guard.
$writers = [
    [ 'class-social-ajax-handlers.php', 'handle_regenerate_worker_secret', 'worker_secret_regenerated' ],
    [ 'class-social-settings.php', 'handle_reset_prompts_action', 'social_prompts_reset' ],
    [ 'class-preset-store.php', 'save_plugin_defaults', 'presets_saved' ],
    [ 'class-preset-store.php', 'save_taxonomy_presets', 'presets_saved' ],
    [ 'class-preset-store.php', 'save_role_presets', 'presets_saved' ],
    [ 'class-editorial-agenda.php', 'save', 'editorial_agenda_saved' ],
    [ 'class-stakeholders.php', 'save_edit', 'person_fiche_edited' ],
    [ 'class-stakeholders.php', 'merge_fiches', 'person_fiches_merged' ],
    [ 'class-social-tiktok-music.php', 'generate_next_unlocked', 'music_track_generated' ],
    [ 'class-social-tiktok-music.php', 'clear_library', 'music_library_cleared' ],
];
foreach ( $writers as [ $file, $method, $event ] ) {
    $body = $body_of( $file, $method );
    $check( '' !== $body, "{$file}::{$method}() exists." );
    $check( false !== strpos( $body, "PressHub_AI_Audit_Logger::log( '{$event}'" ), "{$file}::{$method}() writes the {$event} audit event." );
}
$regen = $body_of( 'class-social-ajax-handlers.php', 'handle_regenerate_worker_secret' );
$check( (bool) preg_match( "/log\( 'worker_secret_regenerated'[^;]*mask_secret\( \\\$secret \)/", $regen ), 'The worker secret event carries only the masked secret.' );
$check( ! preg_match( "/log\( 'worker_secret_regenerated'[^;]*=> \\\$secret\b/", $regen ), 'The worker secret event never carries the secret itself.' );

// 2. Behaviour through the real code.
$events = static function (): array {
    global $wpdb;
    $out = [];
    foreach ( (array) ( $wpdb->tables ?? [] ) as $name => $rows ) {
        if ( false === strpos( (string) $name, 'audit' ) ) {
            continue;
        }
        foreach ( (array) $rows as $row ) {
            $out[] = (string) ( $row['event_type'] ?? '' );
        }
    }
    return $out;
};
$GLOBALS['CURRENT_USER_ID'] = 1;
$count = static function ( string $event ) use ( $events ): int {
    return count( array_keys( $events(), $event, true ) );
};

$before = $count( 'presets_saved' );
PressHub_AI_Preset_Store::save_taxonomy_presets( [] );
PressHub_AI_Preset_Store::save_role_presets( [] );
$check( $count( 'presets_saved' ) >= $before + 2, 'Saving the taxonomy and role presets writes presets_saved twice.' );

$before = $count( 'editorial_agenda_saved' );
PressHub_AI_Editorial_Agenda::save( [], 'Test guidance', 1, 'test' );
$check( $count( 'editorial_agenda_saved' ) === $before + 1, 'Saving the editorial agenda writes editorial_agenda_saved.' );

update_option( PressHub_AI_Stakeholders::OPTION, [ 'jane-doe' => PressHub_AI_Stakeholders::normalize( [ 'name' => 'Jane Doe' ] ) ], false );
$before = $count( 'person_fiche_edited' );
$r      = PressHub_AI_Stakeholders::save_edit( 'jane-doe', [ 'role' => 'Minister' ], 1 );
$check( ! is_wp_error( $r ) && $count( 'person_fiche_edited' ) === $before + 1, 'Editing a people fiche writes person_fiche_edited.' );

// 3. Regenerate worker secret, through the real handler: the event has the
// masked secret only, and never the new secret itself.
$GLOBALS['CURRENT_USER_CAPS'] = [ 'read', 'edit_posts', 'edit_others_posts', 'manage_options' ];
$GLOBALS['NONCE_VALID']       = true;
$GLOBALS['JSON_RESPONSES']    = [];
$_POST                        = [ 'nonce' => 'n' ];
try {
    PressHub_Social_Ajax_Handlers::handle_regenerate_worker_secret();
} catch ( Throwable $t ) {
    // wp_send_json_* stubs throw to end the request.
}
$_POST  = [];
$secret = (string) get_option( 'presshub_social_worker_secret', '' );
$row    = null;
global $wpdb;
foreach ( (array) ( $wpdb->tables ?? [] ) as $name => $rows ) {
    foreach ( (array) $rows as $r_row ) {
        if ( false !== strpos( (string) $name, 'audit' ) && 'worker_secret_regenerated' === ( $r_row['event_type'] ?? '' ) ) {
            $row = $r_row;
        }
    }
}
$check( strlen( $secret ) >= 32, 'Regenerate stores a new worker secret.' );
$check( is_array( $row ), 'Regenerate writes worker_secret_regenerated.' );
$dump = is_array( $row ) ? (string) json_encode( $row ) : '';
$check( '' !== $secret && false === strpos( $dump, $secret ), 'The audit row never contains the new worker secret.' );

if ( $failures ) {
    echo "FAIL\n";
    foreach ( $failures as $f ) {
        echo '  - ' . $f . "\n";
    }
    exit( 1 );
}
echo "SettingsAuditCoverageTest: OK\n";
