<?php
/**
 * #233: docs/settings-inventory.md is generated from the settings registry and
 * must be regenerated whenever the registry changes.
 *
 * Fix a failure with: php presshub-ai-editor/scripts/settings-inventory.php
 */

require_once __DIR__ . '/wordpress-stubs.php';
require_once __DIR__ . '/../scripts/settings-inventory.php';

$target  = dirname( __DIR__, 2 ) . '/docs/settings-inventory.md';
$current = is_readable( $target ) ? file_get_contents( $target ) : '';
$fresh   = presshub_settings_inventory_markdown();

if ( $current !== $fresh ) {
    echo "FAIL\n  - docs/settings-inventory.md is stale. Run: php presshub-ai-editor/scripts/settings-inventory.php\n";
    exit( 1 );
}
if ( false === strpos( $fresh, '| **Total** | **' . count( PressHub_Settings_Registry::all() ) . '** |' ) ) {
    echo "FAIL\n  - inventory total does not match the registry.\n";
    exit( 1 );
}
echo "SettingsInventoryTest: inventory matches the registry.\nOK\n";
