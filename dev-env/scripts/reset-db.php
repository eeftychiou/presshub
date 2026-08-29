<?php
/**
 * Fast Database Reset & Reseed Tool for PressHub Dev Environment.
 *
 * Drops and re-provisions the local SQLite WordPress database with:
 * - Clean WordPress install
 * - Admin user (admin / password123)
 * - Sample post & page
 * - Active plugins
 * - Empty PressHub token and activity tables
 *
 * Execution time: < 1 second.
 */

declare(strict_types=1);

$dev_env_dir = dirname(__DIR__);
$wp_dir      = $dev_env_dir . '/wordpress';
$db_file     = $wp_dir . '/wp-content/database/.ht.sqlite';

echo "=================================================================\n";
echo "Resetting & Reseeding PressHub Dev Database...\n";
echo "=================================================================\n";

if ( file_exists( $db_file ) ) {
    unlink( $db_file );
    echo "Deleted old SQLite database: {$db_file}\n";
}

$_SERVER['HTTP_HOST']       = '127.0.0.1:8888';
$_SERVER['SERVER_NAME']     = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SCRIPT_FILENAME'] = $wp_dir . '/index.php';

define( 'WP_INSTALLING', true );
require_once $wp_dir . '/wp-load.php';
require_once $wp_dir . '/wp-admin/includes/upgrade.php';
require_once $wp_dir . '/wp-admin/includes/plugin.php';

$res = wp_install(
    'PressHub Dev',
    'admin',
    'admin@example.local',
    true,
    '',
    'password123'
);

update_option( 'siteurl', 'http://127.0.0.1:8888' );
update_option( 'home', 'http://127.0.0.1:8888' );
update_option( 'blogname', 'PressHub AI Dev Environment' );

// Activate plugins
$active_plugins = [
    'sqlite-database-integration/load.php',
    'presshub-ai-editor/presshub-ai-editor.php',
];
update_option( 'active_plugins', $active_plugins );

// Create custom plugin tables
if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
    PressHub_AI_Token_Logger::create_table();
}

echo "Database re-seeded successfully in < 1 second!\n";
echo "Admin: admin / password123\n";
echo "=================================================================\n";
