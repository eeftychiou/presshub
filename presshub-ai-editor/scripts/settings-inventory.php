<?php
/**
 * Generates docs/settings-inventory.md from the settings registry (#233).
 *
 * Usage:  php presshub-ai-editor/scripts/settings-inventory.php           (writes the file)
 *         php presshub-ai-editor/scripts/settings-inventory.php --check   (exit 1 if stale)
 *
 * SettingsInventoryTest runs the --check comparison, so CI fails when the
 * registry changes without regenerating the inventory.
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( $tag, $value ) {
        return $value;
    }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

require_once dirname( __DIR__ ) . '/includes/class-settings-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-settings-cleanup.php';

/**
 * Builds the inventory markdown from the declared registry.
 */
function presshub_settings_inventory_markdown(): string {
    $all = PressHub_Settings_Registry::all();

    // #246: one Settings page in sections; options are listed by the section
    // that shows them, with the old page and tab they were stored under.
    $by_section = [];
    $counts     = [];
    foreach ( $all as $key => $e ) {
        $section = PressHub_Settings_Sections::section_of( (string) $key, $e );
        $by_section[ $section ][ $key ] = $e;
        $counts[ $section ]['total'] = ( $counts[ $section ]['total'] ?? 0 ) + 1;
        if ( empty( $e['rendered'] ) ) {
            $counts[ $section ]['hidden'] = ( $counts[ $section ]['hidden'] ?? 0 ) + 1;
        }
        if ( ! empty( $e['secret'] ) ) {
            $counts[ $section ]['secret'] = ( $counts[ $section ]['secret'] ?? 0 ) + 1;
        }
    }
    $sections = PressHub_Settings_Sections::slugs();
    if ( isset( $by_section[''] ) ) {
        $sections[] = '';
    }
    $unused = PressHub_Settings_Registry::unused();

    $md  = "# PressHub settings inventory\n\n";
    $md .= "> Generated from `presshub-ai-editor/includes/settings-registry-data.php` by\n";
    $md .= "> `php presshub-ai-editor/scripts/settings-inventory.php`. Do not edit by hand:\n";
    $md .= "> change the registry and run the script. CI fails when this file is stale.\n\n";
    $md .= "Every option belongs to one section of PressHub → Settings (`admin.php?page=presshub-ai&section=<slug>`), and that section's Save writes it. The Stored as column gives the option's former page and tab (`ai` options were on the AI settings page, `social` options on Social settings).\n\n";
    $md .= "## Summary\n\n";
    $md .= "| Section | Options | Not shown on screen | Secrets |\n|---|---|---|---|\n";
    foreach ( $sections as $slug ) {
        $md .= sprintf( "| %s | %d | %d | %d |\n", '' === $slug ? '**Not in a section**' : PressHub_Settings_Sections::label( $slug ) . ' (`' . $slug . '`)', $counts[ $slug ]['total'] ?? 0, $counts[ $slug ]['hidden'] ?? 0, $counts[ $slug ]['secret'] ?? 0 );
    }
    $md .= sprintf( "| **Total** | **%d** | | |\n\n", count( $all ) );
    $md .= sprintf( "**Scheduled for removal:** %d option(s). **Removed by the clean-up (#237):** %d option(s); both lists are at the end.\n\n", count( $unused ), count( PressHub_Settings_Cleanup::REMOVED ) );

    foreach ( $sections as $slug ) {
        $rows = $by_section[ $slug ] ?? [];
        $md  .= '' === $slug
            ? "## Not in a section (" . count( $rows ) . ")\n\n"
            : sprintf( "## %s (`%s`, %d)\n\n", PressHub_Settings_Sections::label( $slug ), $slug, count( $rows ) );
        if ( ! $rows ) {
            $md .= "None.\n\n";
            continue;
        }
        $md .= "| Option | Label | Type | Unit | Stored as | Notes |\n|---|---|---|---|---|---|\n";
        foreach ( $rows as $key => $e ) {
            $notes = [];
            if ( empty( $e['rendered'] ) ) {
                $notes[] = 'not shown on screen';
            }
            if ( ! empty( $e['secret'] ) ) {
                $notes[] = 'secret, masked';
            }
            if ( ! empty( $e['removes'] ) ) {
                $notes[] = 'removes `' . $e['removes'] . '`';
            }
            if ( ! empty( $e['checkbox'] ) ) {
                $notes[] = 'checkbox';
            }
            if ( PressHub_Settings_Registry::STATUS_UNUSED === ( $e['status'] ?? '' ) ) {
                $notes[] = '**unused**';
            }
            if ( ! empty( $e['depends_on'] ) ) {
                $notes[] = 'needs `' . implode( '` or `', (array) $e['depends_on'] ) . '` on';
            }
            if ( ! empty( $e['aliases'] ) && is_array( $e['aliases'] ) ) {
                $notes[] = 'was: ' . str_replace( [ '|', "\n" ], [ '\\|', ' ' ], implode( '; ', $e['aliases'] ) );
            }
            $label_txt = str_replace( [ '|', "\n" ], [ '\\|', ' ' ], (string) $e['label'] );
            if ( function_exists( 'mb_strlen' ) && mb_strlen( $label_txt ) > 90 ) {
                $label_txt = mb_substr( $label_txt, 0, 87 ) . '…';
            }
            $md .= sprintf( "| `%s` | %s | %s | %s | %s | %s |\n", $key, $label_txt, $e['type'] ?? 'string', str_replace( '|', '\\|', (string) ( $e['unit'] ?? '' ) ), $e['page'] . ' / ' . $e['tab'], implode( ', ', $notes ) );
        }
        $md .= "\n";
    }

    $md .= presshub_settings_inventory_filters( $all );

    $md .= "## Scheduled for removal\n\n";
    if ( ! $unused ) {
        $md .= "None.\n";
    } else {
        $md .= "| Option | Page / tab | Why it is unused |\n|---|---|---|\n";
        foreach ( $unused as $key => $e ) {
            $md .= sprintf( "| `%s` | %s / %s | %s |\n", $key, $e['page'], $e['tab'], str_replace( '|', '\\|', (string) ( $e['unused_reason'] ?? '' ) ) );
        }
    }

    // #237: what the clean-up deleted, and the evidence that nothing read it.
    $evidence = [
        'github'          => 'Plugin updates read `presshub_ai_github_token` only; `get_github_token()` in Social storage had no caller. On upgrade the value is copied into `presshub_ai_github_token` when that one is empty.',
        'x_oauth1'        => 'No code read the X OAuth1 consumer key, consumer secret or access secret; posting to X uses the bearer token, the access token or the worker.',
        'podcast_prompts' => '`resolve_podcast_prompt()` reads only the per-style options (`…_prompt_N_<style>`); the bare names were used as a prefix string, never read. Not shown on any section since #246.',
        'legacy_api_key'    => 'Superseded by the per-provider keys in Connections. Read only as a fallback when a provider record had no key; on presshub.cy it was the same key as the main Gemini provider. On upgrade it is copied into a provider of the `presshub_ai_provider` type when none of that type has a key; a different key is kept unread for one release.',
        'legacy_gemini_key' => 'Never registered or shown; read only as a fallback after the Gemini provider record. Same upgrade rule (copied into the Gemini provider when it has no key).',
        'legacy_speech_key' => 'Speech resolves the provider chosen for Speech in AI models first (`resolve_gemini_api_key()`), then any Gemini provider; the separate key was a later fallback. On presshub.cy it was the same key as the "Google Gemini (3.8 Speech)" provider. Same upgrade rule.',
    ];
    $md .= sprintf( "\n## Removed in %s (#237)\n\n", implode( ' and ', PressHub_Settings_Cleanup::RELEASES ) );
    $md .= "Deleted on the first admin request after the upgrade, after a copy of all settings (no secrets) was saved; the report is on PressHub → Usage & logs. Reading one fires `_doing_it_wrong()` for one release.\n\n";
    $md .= "| Option | Removed in | Evidence it was unused |\n|---|---|---|\n";
    foreach ( PressHub_Settings_Cleanup::REMOVED as $key => $meta ) {
        $note = isset( $meta['key_type'] ) || false === strpos( $key, '_remove_' ) ? ( $evidence[ $meta['group'] ] ?? '' ) : 'The "Remove stored key" flag of the key above.';
        $md  .= sprintf( "| `%s` | %s | %s |\n", $key, PressHub_Settings_Cleanup::RELEASES[ $meta['since'] ], $note );
    }
    return $md;
}

/**
 * Developer filters: every presshub_* filter the plugins apply, with the
 * setting it runs on top of when there is one (#233 step 5).
 */
function presshub_settings_inventory_filters( array $all ): string {
    $backed = [
        'presshub_ai_policy_fiches_enabled'          => 'presshub_ai_policy_fiches_enabled',
        'presshub_ai_stakeholders_enabled'           => 'presshub_ai_stakeholders_enabled',
        'presshub_ai_briefing_claim_check'           => 'presshub_ai_claim_check_enabled',
        'presshub_ai_pool_cleanup_enabled'           => 'presshub_ai_pool_cleanup_enabled',
        'presshub_ai_pool_max_age_hours'             => 'presshub_ai_pool_max_age_hours',
        'presshub_ai_pool_duplicate_similarity'      => 'presshub_ai_pool_duplicate_similarity',
        'presshub_ai_policy_fiches_budgets'          => 'presshub_ai_policy_fiches_budgets',
        'presshub_ai_stakeholders_budgets'           => 'presshub_ai_stakeholders_budgets',
        'presshub_ai_editorial_agenda_budgets'       => 'presshub_ai_editorial_agenda_budgets',
        'presshub_ai_podcast_show_notes_enabled'     => 'presshub_ai_podcast_show_notes_enabled',
        'presshub_social_trending_max_per_account'   => 'presshub_ai_social_trending_max_per_account',
        'presshub_social_trending_share_after_hour'  => 'presshub_ai_social_trending_share_after_hour',
        'presshub_ai_debug_prompts'                  => 'presshub_ai_debug_prompts',
        'presshub_ai_fetch_source_urls'              => 'presshub_ai_fetch_urls',
        'presshub_ai_harvest_time_budget'            => 'presshub_ai_harvest_time_budget',
    ];
    $names = [];
    $files = array_merge( glob( dirname( __DIR__ ) . '/includes/*.php' ) ?: [], [ dirname( __DIR__ ) . '/presshub-ai-editor.php' ] );
    foreach ( $files as $file ) {
        if ( preg_match_all( "/apply_filters\\(\\s*'(presshub_[a-z0-9_]+)'/", (string) file_get_contents( $file ), $m ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a local file or upload, not a remote URL.
            foreach ( $m[1] as $n ) {
                $names[ $n ] = true;
            }
        }
    }
    ksort( $names );
    $md  = "## Developer filters\n\n";
    $md .= "Hooks for developers and hosting. Where a filter has a related setting, the filter adjusts the saved value; the rest stay code-only.\n\n";
    $md .= "| Filter | Related setting |\n|---|---|\n";
    foreach ( array_keys( $names ) as $n ) {
        $opt = $backed[ $n ] ?? '';
        $md .= sprintf( "| `%s` | %s |\n", $n, '' !== $opt && isset( $all[ $opt ] ) ? '`' . $opt . '`' : 'code only' );
    }
    return $md . "\n";
}

if ( PHP_SAPI === 'cli' && isset( $argv ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
    $target = dirname( __DIR__, 2 ) . '/docs/settings-inventory.md';
    $md     = presshub_settings_inventory_markdown();
    if ( in_array( '--check', $argv, true ) ) {
        $current = is_readable( $target ) ? file_get_contents( $target ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reads a local file or upload, not a remote URL.
        if ( $current !== $md ) {
            fwrite( STDERR, "docs/settings-inventory.md is stale. Run: php presshub-ai-editor/scripts/settings-inventory.php\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- direct access to the plugin's own log, cache or temp file; WP_Filesystem is unavailable in cron, AJAX and CLI runs.
            exit( 1 );
        }
        echo "docs/settings-inventory.md is up to date.\n";
        exit( 0 );
    }
    file_put_contents( $target, $md ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- direct access to the plugin's own log, cache or temp file; WP_Filesystem is unavailable in cron, AJAX and CLI runs.
    echo 'Wrote ' . $target . ' (' . count( PressHub_Settings_Registry::all() ) . " options)\n";
}
