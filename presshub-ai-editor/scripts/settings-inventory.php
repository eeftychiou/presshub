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

require_once dirname( __DIR__ ) . '/includes/class-settings-registry.php';

/**
 * Builds the inventory markdown from the declared registry.
 */
function presshub_settings_inventory_markdown(): string {
    $all = PressHub_Settings_Registry::all();

    $pages = [
        'ai'     => 'Settings → PressHub AI',
        'social' => 'PressHub Social → Settings',
    ];
    $counts = [];
    foreach ( $all as $e ) {
        $p = $e['page'];
        $t = $e['tab'];
        $counts[ $p ]['total']  = ( $counts[ $p ]['total'] ?? 0 ) + 1;
        $counts[ $p ]['tabs'][ $t ] = ( $counts[ $p ]['tabs'][ $t ] ?? 0 ) + 1;
        if ( empty( $e['rendered'] ) ) {
            $counts[ $p ]['hidden'] = ( $counts[ $p ]['hidden'] ?? 0 ) + 1;
        }
        if ( ! empty( $e['secret'] ) ) {
            $counts[ $p ]['secret'] = ( $counts[ $p ]['secret'] ?? 0 ) + 1;
        }
    }
    $unused = PressHub_Settings_Registry::unused();

    $md  = "# PressHub settings inventory\n\n";
    $md .= "> Generated from `presshub-ai-editor/includes/settings-registry-data.php` by\n";
    $md .= "> `php presshub-ai-editor/scripts/settings-inventory.php`. Do not edit by hand:\n";
    $md .= "> change the registry and run the script. CI fails when this file is stale.\n\n";
    $md .= "## Summary\n\n";
    $md .= "| Page | Options | Not shown on screen | Secrets |\n|---|---|---|---|\n";
    foreach ( $pages as $p => $label ) {
        $md .= sprintf( "| %s | %d | %d | %d |\n", $label, $counts[ $p ]['total'] ?? 0, $counts[ $p ]['hidden'] ?? 0, $counts[ $p ]['secret'] ?? 0 );
    }
    $md .= sprintf( "| **Total** | **%d** | | |\n\n", count( $all ) );
    $md .= sprintf( "**Scheduled for removal:** %d option(s), see the last section. They are still registered and keep their stored values until the clean-up phase (#237).\n\n", count( $unused ) );

    foreach ( $pages as $p => $label ) {
        $md .= "## {$label}\n\n";
        $tabs = $counts[ $p ]['tabs'] ?? [];
        foreach ( $tabs as $tab => $n ) {
            $md .= "### Tab `{$tab}` ({$n})\n\n";
            $md .= "| Option | Label | Type | Notes |\n|---|---|---|---|\n";
            foreach ( PressHub_Settings_Registry::for_tab( $p, $tab ) as $key => $e ) {
                if ( $e['tab'] !== $tab ) {
                    continue;
                }
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
                $label_txt = str_replace( [ '|', "\n" ], [ '\\|', ' ' ], (string) $e['label'] );
                if ( function_exists( 'mb_strlen' ) && mb_strlen( $label_txt ) > 90 ) {
                    $label_txt = mb_substr( $label_txt, 0, 87 ) . '…';
                }
                $md .= sprintf( "| `%s` | %s | %s | %s |\n", $key, $label_txt, $e['type'] ?? 'string', implode( ', ', $notes ) );
            }
            $md .= "\n";
        }
    }

    $md .= "## Scheduled for removal\n\n";
    if ( ! $unused ) {
        $md .= "None.\n";
    } else {
        $md .= "| Option | Page / tab | Why it is unused |\n|---|---|---|\n";
        foreach ( $unused as $key => $e ) {
            $md .= sprintf( "| `%s` | %s / %s | %s |\n", $key, $e['page'], $e['tab'], str_replace( '|', '\\|', (string) ( $e['unused_reason'] ?? '' ) ) );
        }
    }
    return $md;
}

if ( PHP_SAPI === 'cli' && isset( $argv ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
    $target = dirname( __DIR__, 2 ) . '/docs/settings-inventory.md';
    $md     = presshub_settings_inventory_markdown();
    if ( in_array( '--check', $argv, true ) ) {
        $current = is_readable( $target ) ? file_get_contents( $target ) : '';
        if ( $current !== $md ) {
            fwrite( STDERR, "docs/settings-inventory.md is stale. Run: php presshub-ai-editor/scripts/settings-inventory.php\n" );
            exit( 1 );
        }
        echo "docs/settings-inventory.md is up to date.\n";
        exit( 0 );
    }
    file_put_contents( $target, $md );
    echo 'Wrote ' . $target . ' (' . count( PressHub_Settings_Registry::all() ) . " options)\n";
}
