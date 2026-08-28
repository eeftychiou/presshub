<?php
/**
 * Plugin Name: PressHub AI Co-Pilot
 * Description: AI Co-Authoring and Editorial Workflow for PressHub.
 * Version: 1.9.2
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Tested up to: 6.7
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: presshub-ai-editor
 * Domain Path: /languages
 * Author: Antigravity
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 1.2.8 migration: the default max_tokens rose 2000 -> 3000 -> 10000.
 * A stored per-provider max_tokens option beats the default, so installs
 * that saved settings while 2000 was the default would stay capped at
 * 2000 forever. If a stored value equals the OLD default (2000) it was
 * almost certainly never a deliberate choice — delete it so the new
 * default applies. Custom values (anything else) are left untouched.
 * Runs once; guarded by the presshub_ai_migrated_max_tokens flag.
 */
if ( ! function_exists( 'presshub_ai_migrate_max_tokens_defaults' ) ) {
    function presshub_ai_migrate_max_tokens_defaults() {
        if ( '1' === get_option( 'presshub_ai_migrated_max_tokens', '0' ) ) {
            return;
        }
        foreach ( array( 'openai', 'anthropic', 'gemini' ) as $provider ) {
            $option = 'presshub_ai_max_tokens_' . $provider;
            if ( 2000 === (int) get_option( $option, 0 ) ) {
                delete_option( $option );
            }
        }
        update_option( 'presshub_ai_migrated_max_tokens', '1', false );
    }
}
add_action( 'admin_init', 'presshub_ai_migrate_max_tokens_defaults' );

define( 'PRESSHUB_AI_VERSION', '1.9.2' );
define( 'PRESSHUB_AI_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSHUB_AI_URL', plugin_dir_url( __FILE__ ) );

// Include classes
require_once PRESSHUB_AI_DIR . 'includes/class-provider-defaults.php';
require_once PRESSHUB_AI_DIR . 'includes/class-provider-store.php';
require_once PRESSHUB_AI_DIR . 'includes/class-logger.php';
require_once PRESSHUB_AI_DIR . 'includes/class-settings.php';
require_once PRESSHUB_AI_DIR . 'includes/class-metaboxes.php';
require_once PRESSHUB_AI_DIR . 'includes/class-ajax-handlers.php';
require_once PRESSHUB_AI_DIR . 'includes/class-api-client.php';
require_once PRESSHUB_AI_DIR . 'includes/class-workflow.php';
require_once PRESSHUB_AI_DIR . 'includes/class-research-cleanup.php';
require_once PRESSHUB_AI_DIR . 'includes/class-preset-sanitizer.php';
require_once PRESSHUB_AI_DIR . 'includes/class-preset-store.php';
require_once PRESSHUB_AI_DIR . 'includes/class-preset-resolver.php';
require_once PRESSHUB_AI_DIR . 'includes/class-admin-presets.php';
require_once PRESSHUB_AI_DIR . 'includes/class-author-presets.php';
require_once PRESSHUB_AI_DIR . 'includes/class-news-harvester.php';
require_once PRESSHUB_AI_DIR . 'includes/class-news-curator.php';
require_once PRESSHUB_AI_DIR . 'includes/class-podcast-producer.php';
require_once PRESSHUB_AI_DIR . 'includes/class-audio-synthesizer.php';
require_once PRESSHUB_AI_DIR . 'includes/class-briefing-admin.php';
require_once PRESSHUB_AI_DIR . 'includes/class-token-logger.php';

/**
 * Auto-update hardening: force the canonical plugin folder name during
 * upgrades.
 *
 * WordPress renames the freshly-extracted update folder to match the
 * name of the INSTALLED plugin folder. If that folder was renamed or
 * typo'd (e.g. 'pressshub-ai-editor') the rename target already exists
 * and the update fails with "Unable to rename the update to match the
 * existing directory". Forcing destination_name makes WP always install
 * into the canonical 'presshub-ai-editor' folder and re-point the
 * active-plugin entry, so folder-name drift can never block updates.
 *
 * @since 1.2.0
 */
add_filter(
    'upgrader_package_options',
    function ( $options ) {
        if ( empty( $options['hook_extra']['plugin'] ) || ! is_string( $options['hook_extra']['plugin'] ) ) {
            return $options;
        }
        if ( false !== strpos( $options['hook_extra']['plugin'], 'presshub-ai-editor.php' ) ) {
            $options['destination_name'] = 'presshub-ai-editor';
        }
        return $options;
    }
);

/**
 * Activation: create custom database tables and schedule background crons.
 */
function presshub_ai_activate() {
    PressHub_AI_Token_Logger::create_table();
    if ( function_exists( 'presshub_ai_schedule_briefing_crons' ) ) {
        presshub_ai_schedule_briefing_crons();
    }
    if ( ! wp_next_scheduled( 'presshub_ai_prune_token_logs' ) ) {
        wp_schedule_event( time(), 'daily', 'presshub_ai_prune_token_logs' );
    }
}
register_activation_hook( __FILE__, 'presshub_ai_activate' );

/**
 * Deactivation: clear all scheduled crons so a deactivated plugin stops
 * scheduling work. Options, user metas and research posts are deliberately
 * kept on deactivation — uninstall.php removes them on full uninstall.
 */
function presshub_ai_deactivate() {
    wp_clear_scheduled_hook( 'presshub_ai_cleanup_research' );
    wp_clear_scheduled_hook( 'presshub_ai_do_research' );
    wp_clear_scheduled_hook( 'presshub_daily_news_harvest' );
    wp_clear_scheduled_hook( 'presshub_daily_news_generate' );
    wp_clear_scheduled_hook( 'presshub_ai_prune_token_logs' );
}
register_deactivation_hook( __FILE__, 'presshub_ai_deactivate' );

// Prune token logs cron action
add_action( 'presshub_ai_prune_token_logs', function() {
    if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
        PressHub_AI_Token_Logger::prune_old_logs( 60 );
    }
} );


// Initialize GitHub Update Checker
// PRESSHUB_AI_SKIP_UPDATE_CHECKER lets hosts (and the unit-test harness)
// disable the embedded updater without deleting the library.
if ( ! defined( 'PRESSHUB_AI_SKIP_UPDATE_CHECKER' )
    && file_exists( PRESSHUB_AI_DIR . 'includes/plugin-update-checker/plugin-update-checker.php' ) ) {
    require_once PRESSHUB_AI_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';
    
    $presshub_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/eeftychiou/presshub/',
        __FILE__,
        'presshub-ai-editor'
    );
    
    $presshub_update_checker->setBranch( 'main' );
    $presshub_update_checker->getVcsApi()->enableReleaseAssets();
    
    $github_token = get_option( 'presshub_ai_github_token' );
    if ( ! empty( $github_token ) ) {
        $presshub_update_checker->setAuthentication( $github_token );
    }
}

function presshub_ai_init() {
    new PressHub_AI_Settings();
    new PressHub_AI_Metaboxes();
    new PressHub_AI_Ajax_Handlers();
    new PressHub_AI_Workflow();
    new PressHub_AI_Briefing_Admin();
    PressHub_AI_Research_Cleanup::register();

    if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
        if ( get_option( PressHub_AI_Token_Logger::DB_VERSION_OPTION ) !== PressHub_AI_Token_Logger::DB_VERSION ) {
            PressHub_AI_Token_Logger::create_table();
        }
        if ( ! wp_next_scheduled( 'presshub_ai_prune_token_logs' ) ) {
            wp_schedule_event( time(), 'daily', 'presshub_ai_prune_token_logs' );
        }
    }
}
add_action( 'plugins_loaded', 'presshub_ai_init' );

add_action( 'init', function() {
    register_post_type( 'presshub_research', [
        'labels' => [
            'name' => __( 'AI Research Logs', 'presshub-ai-editor' ),
            'singular_name' => __( 'AI Research Log', 'presshub-ai-editor' ),
        ],
        'public' => false,
        'show_ui' => false,
        'supports' => [ 'title', 'editor', 'custom-fields' ],
    ] );
} );


add_action( 'enqueue_block_editor_assets', function() {
    wp_enqueue_script(
        'presshub-ai-sidebar',
        PRESSHUB_AI_URL . 'assets/sidebar.js',
        ['jquery', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-i18n'],
        PRESSHUB_AI_VERSION,
        true
    );
    wp_localize_script( 'presshub-ai-sidebar', 'presshubAI', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'presshub_ai_nonce' )
    ] );
});

add_action( 'presshub_ai_do_research', 'presshub_ai_execute_research_job' );

/**
 * NOTE ON WP-CRON DEPENDENCY:
 * Deep-research jobs are scheduled with wp_schedule_single_event(), which
 * only fires when WordPress itself is loaded (typically on site traffic).
 * On low-traffic sites a research job may sit 'pending' until someone
 * visits. For reliable execution, configure a real cron on the server:
 *
 *   wp config set DISABLE_WP_CRON true --raw
 *   # crontab:  * * * * * wp cron event run --due-now --path=/path/to/wp
 *
 * The AJAX response to the client includes 'scheduled_at' so the UI can
 * surface the expected execution time.
 */
function presshub_ai_execute_research_job( $research_id ) {
    $research_post = get_post( $research_id );
    if ( ! $research_post || 'presshub_research' !== $research_post->post_type ) {
        return;
    }

    // C-2 (Antigravity review): WP-Cron can fire the same scheduled event
    // twice (overlapping executions, manual `wp cron event run` while a
    // request-triggered run is still in flight). Once a job is
    // 'processing', a duplicate invocation must not start a second API
    // call — the first execution owns the job.
    if ( 'processing' === get_post_meta( $research_id, '_research_status', true ) ) {
        return;
    }

    update_post_meta( $research_id, '_research_status', 'processing' );
    
    $prompt = get_post_meta( $research_id, '_research_prompt', true );
    $associated_post_id = get_post_meta( $research_id, '_associated_post_id', true );
    
    $associated_post = get_post( $associated_post_id );
    $post_content = $associated_post ? $associated_post->post_content : '';
    
    $api = new PressHub_AI_API_Client();
    
    $sys_prompt = __( "You are a senior investigative research assistant. Your task is to perform an in-depth topic synthesis and research synthesis.\nUse the provided instructions and the current post draft context to compile a comprehensive, well-structured, and objective research report in clean HTML format. Use headings, lists, and quotes where appropriate. DO NOT output code block wrappers (like ```html). Only output the raw HTML.", 'presshub-ai-editor' );

    // C-3 (Antigravity review): the base prompt is filtered FIRST so a
    // filter that replaces the default keeps working, then the resolved
    // per-author preset is appended on top, and the final composition is
    // exposed through a dedicated hook for consumers that need to see or
    // adjust the whole composed prompt.
    $sys_prompt = apply_filters( 'presshub_ai_research_system_prompt', $sys_prompt );

    // Per-author instruction presets (2026-08-15 design §3.3): the author
    // who initiated the research gets their preset appended to the system
    // prompt. Unknown/absent initiator (user id 0) resolves to null and
    // leaves the prompt untouched.
    $research_user_id = (int) get_post_meta( $research_id, '_research_user_id', true );
    $preset = PressHub_AI_Preset_Resolver::resolve_for_user( $research_user_id, 'research', null );
    if ( $preset !== null ) {
        $sys_prompt .= "\n\n" . $preset;
    }

    $sys_prompt = apply_filters( 'presshub_ai_composed_research_system_prompt', $sys_prompt );

    $user_prompt = "User prompt / request: " . $prompt . "\n\nAssociated Post Content Context:\n" . $post_content;

    $report = $api->call_provider( $sys_prompt, $user_prompt, false, [] );

    // 1.2.9: research syntheses are inserted as HTML blocks — convert
    // Markdown output so the editor renders them properly.
    if ( ! is_wp_error( $report ) ) {
        $report = PressHub_AI_Markdown::to_html( $report );
    }

    presshub_ai_log_prompts( 'research', $sys_prompt, $user_prompt, is_wp_error( $report ) ? 'ERROR: ' . $report->get_error_message() : $report, PressHub_AI_API_Client::current_request_meta() );
    
    if ( is_wp_error( $report ) ) {
        update_post_meta( $research_id, '_research_status', 'failed' );
        update_post_meta( $research_id, '_error_message', $report->get_error_message() );
        error_log( sprintf(
            'PressHub AI: research job %d failed (provider error): %s',
            $research_id,
            $report->get_error_message()
        ) );
        return;
    }
    
    $updated = wp_update_post( [
        'ID' => $research_id,
        'post_content' => wp_kses_post( $report )
    ] );
    
    if ( is_wp_error( $updated ) || 0 === $updated ) {
        $update_error = is_wp_error( $updated ) ? $updated->get_error_message() : 'Failed to update research post content.';
        update_post_meta( $research_id, '_research_status', 'failed' );
        update_post_meta( $research_id, '_error_message', $update_error );
        error_log( sprintf(
            'PressHub AI: research job %d failed (post update): %s',
            $research_id,
            $update_error
        ) );
        return;
    }

    update_post_meta( $research_id, '_research_status', 'completed' );
}

/**
 * ----------------------------------------------------------------------
 * Daily News Briefing & AI Podcast WP-Cron Scheduling & Execution
 * ----------------------------------------------------------------------
 */

add_action( 'presshub_daily_news_harvest', 'presshub_ai_execute_harvest_cron' );
add_action( 'presshub_daily_news_generate', 'presshub_ai_execute_generation_cron' );
add_action( 'update_option_presshub_ai_briefing_harvest_time', 'presshub_ai_on_briefing_time_updated', 10, 3 );
add_action( 'update_option_presshub_ai_briefing_generation_time', 'presshub_ai_on_briefing_time_updated', 10, 3 );
register_activation_hook( __FILE__, 'presshub_ai_schedule_briefing_crons' );

/**
 * Handle updates to briefing time options: only reschedule when value changed.
 *
 * @param mixed $old_value Previous option value.
 * @param mixed $value     New option value.
 * @param mixed $option    Option name.
 */
function presshub_ai_on_briefing_time_updated( $old_value = null, $value = null, $option = null ) {
    if ( null !== $old_value && null !== $value && (string) $old_value === (string) $value ) {
        return;
    }
    wp_clear_scheduled_hook( 'presshub_daily_news_harvest' );
    wp_clear_scheduled_hook( 'presshub_daily_news_generate' );
    presshub_ai_schedule_briefing_crons();
}

/**
 * Calculate the next timestamp for a given HH:MM time string.
 *
 * @param string   $time_str Time in HH:MM format.
 * @param int|null $now      Optional reference timestamp (defaults to current time).
 * @return int Unix timestamp for the next occurrence.
 */
function presshub_ai_get_cron_timestamp( $time_str, $now = null ) {
    if ( null === $now ) {
        $now = $GLOBALS['TIME_NOW'] ?? time();
    }
    if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', trim( (string) $time_str ), $matches ) ) {
        $time_str = '06:30';
        $matches  = [ $time_str, '06', '30' ];
    }
    $hours   = (int) $matches[1];
    $minutes = (int) $matches[2];

    if ( function_exists( 'wp_timezone' ) ) {
        $tz = wp_timezone();
    } else {
        $tz = new DateTimeZone( 'UTC' );
    }

    try {
        $today = new DateTime( '@' . $now );
        $today->setTimezone( $tz );
        $today->setTime( $hours, $minutes, 0 );
        $target = $today->getTimestamp();
        if ( $target <= $now ) {
            $today->modify( '+1 day' );
            $target = $today->getTimestamp();
        }
        return $target;
    } catch ( Throwable $e ) {
        $today_date = date( 'Y-m-d', $now );
        $target     = strtotime( sprintf( '%s %02d:%02d:00', $today_date, $hours, $minutes ) );
        if ( $target <= $now ) {
            $target += 86400; // DAY_IN_SECONDS
        }
        return $target;
    }
}

/**
 * Schedule or re-schedule the daily morning news harvest and generation cron events.
 */
function presshub_ai_schedule_briefing_crons() {
    try {
        $harvest_time    = (string) get_option( 'presshub_ai_briefing_harvest_time', '06:30' );
        $generation_time = (string) get_option( 'presshub_ai_briefing_generation_time', '07:15' );

        wp_clear_scheduled_hook( 'presshub_daily_news_harvest' );
        wp_clear_scheduled_hook( 'presshub_daily_news_generate' );

        $harvest_timestamp    = presshub_ai_get_cron_timestamp( $harvest_time );
        $generation_timestamp = presshub_ai_get_cron_timestamp( $generation_time );

        wp_schedule_event( $harvest_timestamp, 'daily', 'presshub_daily_news_harvest' );
        wp_schedule_event( $generation_timestamp, 'daily', 'presshub_daily_news_generate' );
    } catch ( Throwable $t ) {
        if ( class_exists( 'PressHub_AI_Logger' ) ) {
            PressHub_AI_Logger::warning( 'Cron scheduling error: ' . $t->getMessage() );
        }
    }
}

/**
 * Execute the automated Greek news harvest cron.
 *
 * @return array Harvest payload.
 */
function presshub_ai_execute_harvest_cron() {
    $harvester   = new PressHub_AI_News_Harvester();
    $raw_sources = get_option( 'presshub_ai_briefing_sources', '' );
    if ( is_array( $raw_sources ) ) {
        $sources = $raw_sources;
    } else {
        $sources = preg_split( '/[\r\n,]+/', (string) $raw_sources );
    }
    $sources = array_values( array_filter( array_map( 'trim', (array) $sources ) ) );

    if ( empty( $sources ) && class_exists( 'PressHub_AI_Settings' ) ) {
        $sources = PressHub_AI_Settings::default_briefing_sources();
    }

    return $harvester->harvest_all( $sources );
}

/**
 * Execute the automated news briefing generation cron (Text Story + Podcast Dialogue + Audio Synthesis).
 *
 * @return array Generated results payload.
 */
function presshub_ai_execute_generation_cron() {
    $date = gmdate( 'Y-m-d' );
    $results = [
        'curation'  => null,
        'podcast'   => null,
        'synthesis' => null,
    ];

    // 1. Text story curation
    $curator = new PressHub_AI_News_Curator();
    $preset_text = (string) get_option( 'presshub_ai_briefing_text_preset', '' );
    $results['curation'] = $curator->generate_story( $date, null, $preset_text );

    // 2. Podcast dialogue script generation
    $producer = new PressHub_AI_Podcast_Producer();
    $preset_podcast = (string) get_option( 'presshub_ai_briefing_podcast_preset', '' );
    $duration = (string) get_option( 'presshub_ai_briefing_target_duration', '5_min' );
    $results['podcast'] = $producer->generate_dialogue_script( $date, null, $preset_podcast, $duration );

    // 3. Audio podcast synthesis
    if ( ! is_wp_error( $results['podcast'] ) ) {
        $synthesizer = new PressHub_AI_Audio_Synthesizer();
        $results['synthesis'] = $synthesizer->synthesize_podcast( $date );
    }

    return $results;
}


