<?php
/**
 * Database Reset & Reseed Utility for PressHub Dev Environment.
 *
 * Drops and recreates the SQLite database to a clean slate.
 */

declare(strict_types=1);

$dev_env_dir = dirname(__DIR__);
$wp_dir      = $dev_env_dir . '/wordpress';
$db_file     = $wp_dir . '/wp-content/database/.ht.sqlite';
$ph_log_file = $wp_dir . '/wp-content/uploads/presshub-ai/presshub-debug.log';
$wp_log_file = $wp_dir . '/wp-content/debug.log';

echo "Resetting WordPress database and logs...\n";

// Remove SQLite DB
if ( file_exists( $db_file ) ) {
    @unlink( $db_file );
}

// Remove logs
if ( file_exists( $ph_log_file ) ) {
    @unlink( $ph_log_file );
}
if ( file_exists( $wp_log_file ) ) {
    @unlink( $wp_log_file );
}

// Bootstrap WordPress installation
$_SERVER['HTTP_HOST']       = '127.0.0.1:8888';
$_SERVER['SERVER_NAME']     = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SCRIPT_FILENAME'] = $wp_dir . '/index.php';

define( 'WP_INSTALLING', true );
require_once $wp_dir . '/wp-load.php';
require_once $wp_dir . '/wp-admin/includes/upgrade.php';
require_once $wp_dir . '/wp-admin/includes/plugin.php';
require_once $wp_dir . '/wp-admin/includes/post.php';

wp_install(
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
$plugins = [
    'sqlite-database-integration/load.php',
    'presshub-ai-editor/presshub-ai-editor.php',
];
update_option( 'active_plugins', $plugins );

// Create token logger table
if ( file_exists( $wp_dir . '/wp-content/plugins/presshub-ai-editor/includes/class-token-logger.php' ) ) {
    require_once $wp_dir . '/wp-content/plugins/presshub-ai-editor/includes/class-token-logger.php';
    if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
        PressHub_AI_Token_Logger::create_table();
    }
}

// Create sample test post
$post_id = wp_insert_post( [
    'post_title'   => 'Sample Editorial Article for PressHub Testing',
    'post_content' => '<!-- wp:paragraph --><p>This is a sample post created for testing PressHub AI Editor editorial features, co-authoring, and podcast generation.</p><!-- /wp:paragraph -->',
    'post_status'  => 'publish',
    'post_author'  => 1,
] );

echo "Database reset complete!\n";
echo "Admin user: admin / password123\n";
echo "Sample post created with ID: {$post_id}\n";
