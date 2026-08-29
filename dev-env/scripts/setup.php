<?php
/**
 * Setup Script for PressHub Local WordPress Development Environment.
 *
 * Downloads WordPress Core, SQLite drop-in, WP-CLI, configures wp-config.php,
 * links the presshub-ai-editor plugin, installs WordPress database, and initializes tables.
 */

declare(strict_types=1);

$root_dir     = dirname(__DIR__, 2);
$dev_env_dir  = dirname(__DIR__);
$wp_dir       = $dev_env_dir . '/wordpress';
$bin_dir      = $dev_env_dir . '/bin';
$downloads_dir = $dev_env_dir . '/downloads';
$plugin_src   = $root_dir . '/presshub-ai-editor';

echo "=================================================================\n";
echo "PressHub Local WordPress Development Environment Setup\n";
echo "=================================================================\n\n";

// Ensure directories exist
foreach ( [ $dev_env_dir, $wp_dir, $bin_dir, $downloads_dir ] as $dir ) {
    if ( ! is_dir( $dir ) ) {
        mkdir( $dir, 0777, true );
    }
}

// 1. Download WordPress Core if missing
$wp_version_file = $wp_dir . '/wp-includes/version.php';
if ( ! file_exists( $wp_version_file ) ) {
    echo "[1/6] Downloading WordPress Core...\n";
    $wp_zip = $downloads_dir . '/wordpress.zip';
    
    if ( ! file_exists( $wp_zip ) || filesize( $wp_zip ) < 1000000 ) {
        $wp_url = 'https://wordpress.org/latest.zip';
        download_file( $wp_url, $wp_zip );
    }

    echo "      Extracting WordPress Core to {$wp_dir}...\n";
    $zip = new ZipArchive();
    if ( true === $zip->open( $wp_zip ) ) {
        $temp_extract = $downloads_dir . '/wp_extract';
        if ( is_dir( $temp_extract ) ) {
            delete_directory( $temp_extract );
        }
        mkdir( $temp_extract, 0777, true );
        $zip->extractTo( $temp_extract );
        $zip->close();

        $extracted_wp = $temp_extract . '/wordpress';
        if ( is_dir( $extracted_wp ) ) {
            copy_directory( $extracted_wp, $wp_dir );
            delete_directory( $temp_extract );
        }
        echo "      WordPress Core extracted successfully.\n";
    } else {
        exit( "Error: Failed to open {$wp_zip}\n" );
    }
} else {
    echo "[1/6] WordPress Core is already present.\n";
}

// 2. Download and install SQLite Integration Plugin
$sqlite_plugin_dir = $wp_dir . '/wp-content/plugins/sqlite-database-integration';
$db_dropin_file    = $wp_dir . '/wp-content/db.php';

echo "[2/6] Configuring SQLite Database Drop-in...\n";
if ( ! is_dir( $sqlite_plugin_dir ) ) {
    $sqlite_zip = $downloads_dir . '/sqlite-database-integration.zip';
    if ( ! file_exists( $sqlite_zip ) || filesize( $sqlite_zip ) < 10000 ) {
        $sqlite_url = 'https://downloads.wordpress.org/plugin/sqlite-database-integration.zip';
        download_file( $sqlite_url, $sqlite_zip );
    }

    $zip = new ZipArchive();
    if ( true === $zip->open( $sqlite_zip ) ) {
        $zip->extractTo( $wp_dir . '/wp-content/plugins/' );
        $zip->close();
        echo "      SQLite Integration plugin extracted.\n";
    }
}

// Install db.php dropin from sqlite plugin
$db_copy_file = $sqlite_plugin_dir . '/db.copy';
if ( file_exists( $db_copy_file ) ) {
    copy( $db_copy_file, $db_dropin_file );
    echo "      Copied db.copy -> wp-content/db.php.\n";
} elseif ( ! file_exists( $db_dropin_file ) ) {
    exit( "Error: Could not locate db.copy to create wp-content/db.php\n" );
}

// Ensure database directory exists
$db_dir = $wp_dir . '/wp-content/database';
if ( ! is_dir( $db_dir ) ) {
    mkdir( $db_dir, 0777, true );
}

// 3. Download WP-CLI and create wrapper
echo "[3/6] Setting up WP-CLI...\n";
$wp_cli_phar = $bin_dir . '/wp-cli.phar';
if ( ! file_exists( $wp_cli_phar ) || filesize( $wp_cli_phar ) < 1000000 ) {
    $wp_cli_url = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';
    download_file( $wp_cli_url, $wp_cli_phar );
    echo "      WP-CLI downloaded to {$wp_cli_phar}.\n";
} else {
    echo "      WP-CLI is already present.\n";
}

$wp_bat_content = "@echo off\r\nphp \"%~dp0wp-cli.phar\" --path=\"%~dp0..\\wordpress\" %*\r\n";
file_put_contents( $bin_dir . '/wp.bat', $wp_bat_content );

// 4. Configure wp-config.php
echo "[4/6] Creating wp-config.php...\n";
$wp_config_file = $wp_dir . '/wp-config.php';
$secret_keys = generate_salts();

$wp_config_content = <<<PHP
<?php
/**
 * WordPress Local Dev Environment Configuration for PressHub AI Editor
 */

define( 'DB_NAME', 'presshub_dev' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

{$secret_keys}

\$table_prefix = 'wp_';

// Development & Debugging Settings
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
@ini_set( 'display_errors', '0' );
define( 'SCRIPT_DEBUG', true );
define( 'SAVEQUERIES', true );
define( 'WP_ENVIRONMENT_TYPE', 'local' );

// Host and URL constants
define( 'WP_HOME', 'http://127.0.0.1:8888' );
define( 'WP_SITEURL', 'http://127.0.0.1:8888' );

// Allow direct file modifications
define( 'FS_METHOD', 'direct' );

// Disable automatic updates in dev
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_AUTO_UPDATE_CORE', false );

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
PHP;

file_put_contents( $wp_config_file, $wp_config_content );
echo "      wp-config.php configured with debug logging enabled.\n";

// 5. Link presshub-ai-editor plugin into wp-content/plugins
echo "[5/6] Linking plugin presshub-ai-editor...\n";
$target_plugin_link = $wp_dir . '/wp-content/plugins/presshub-ai-editor';

if ( is_dir( $target_plugin_link ) || is_link( $target_plugin_link ) ) {
    echo "      Plugin link already exists.\n";
} else {
    $cmd = sprintf( 'cmd /c mklink /J "%s" "%s"', str_replace( '/', '\\', $target_plugin_link ), str_replace( '/', '\\', $plugin_src ) );
    exec( $cmd, $output, $ret );
    if ( 0 === $ret ) {
        echo "      Created junction: wp-content/plugins/presshub-ai-editor -> presshub-ai-editor\n";
    } else {
        echo "      Junction creation notice (fallback copy if needed): " . implode( ' ', $output ) . "\n";
        if ( ! is_dir( $target_plugin_link ) ) {
            copy_directory( $plugin_src, $target_plugin_link );
            echo "      Copied plugin files as fallback.\n";
        }
    }
}

// 6. Install WordPress database and activate plugin
echo "[6/6] Initializing WordPress Database & Activating Plugin...\n";

$_SERVER['HTTP_HOST']       = '127.0.0.1:8888';
$_SERVER['SERVER_NAME']     = '127.0.0.1';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SCRIPT_FILENAME'] = $wp_dir . '/index.php';

define( 'WP_INSTALLING', true );
require_once $wp_dir . '/wp-load.php';
require_once $wp_dir . '/wp-admin/includes/upgrade.php';
require_once $wp_dir . '/wp-admin/includes/plugin.php';

global $wpdb;

if ( ! is_blog_installed() ) {
    echo "      Running wp_install()...\n";
    $install_result = wp_install(
        'PressHub Dev',
        'admin',
        'admin@example.local',
        true,
        '',
        'password123'
    );
    echo "      WordPress installed successfully! (Admin: admin / password123)\n";
} else {
    echo "      WordPress database already installed.\n";
}

// Update home and siteurl to local dev server
update_option( 'siteurl', 'http://127.0.0.1:8888' );
update_option( 'home', 'http://127.0.0.1:8888' );
update_option( 'blogname', 'PressHub AI Dev Environment' );

// Activate SQLite plugin & PressHub AI Editor
$active_plugins = (array) get_option( 'active_plugins', [] );

$plugins_to_activate = [
    'sqlite-database-integration/load.php',
    'presshub-ai-editor/presshub-ai-editor.php',
];

foreach ( $plugins_to_activate as $plugin ) {
    if ( ! in_array( $plugin, $active_plugins, true ) ) {
        $active_plugins[] = $plugin;
        echo "      Activating {$plugin}...\n";
    }
}

update_option( 'active_plugins', array_values( array_unique( $active_plugins ) ) );

// Trigger Token Logger table creation
if ( file_exists( $wp_dir . '/wp-content/plugins/presshub-ai-editor/includes/class-token-logger.php' ) ) {
    require_once $wp_dir . '/wp-content/plugins/presshub-ai-editor/includes/class-token-logger.php';
    if ( class_exists( 'PressHub_AI_Token_Logger' ) ) {
        PressHub_AI_Token_Logger::create_table();
        echo "      Verified table: " . PressHub_AI_Token_Logger::get_table_name() . "\n";
    }
}

// Ensure upload and logging directories exist
$upload_dir = wp_upload_dir();
$log_dir = trailingslashit( $upload_dir['basedir'] ) . 'presshub-ai';
if ( ! is_dir( $log_dir ) ) {
    mkdir( $log_dir, 0777, true );
}

echo "\n=================================================================\n";
echo "SUCCESS: Local WordPress Development Environment is Ready!\n";
echo "=================================================================\n";
echo "URL:            http://127.0.0.1:8888\n";
echo "Admin URL:      http://127.0.0.1:8888/wp-admin/\n";
echo "Username:       admin\n";
echo "Password:       password123\n";
echo "Database:       dev-env/wordpress/wp-content/database/.ht.sqlite\n";
echo "Debug Log:      dev-env/wordpress/wp-content/debug.log\n";
echo "PressHub Log:   dev-env/wordpress/wp-content/uploads/presshub-ai/presshub-debug.log\n";
echo "=================================================================\n";
echo "To start the web server, run:\n";
echo "  php dev-env/scripts/server.php\n\n";

// Helper functions
function download_file( string $url, string $dest ): void {
    $fp = fopen( $dest, 'w+' );
    if ( ! $fp ) {
        exit( "Error: Cannot open {$dest} for writing.\n" );
    }

    $ch = curl_init( $url );
    curl_setopt_array( $ch, [
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'PressHub-Dev-Setup/1.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ] );

    $success = curl_exec( $ch );
    $http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
    curl_close( $ch );
    fclose( $fp );

    if ( ! $success || $http_code >= 400 ) {
        @unlink( $dest );
        exit( "Error downloading {$url} (HTTP {$http_code})\n" );
    }
}

function copy_directory( string $src, string $dst ): void {
    $dir = opendir( $src );
    @mkdir( $dst, 0777, true );
    while ( false !== ( $file = readdir( $dir ) ) ) {
        if ( ( $file !== '.' ) && ( $file !== '..' ) ) {
            if ( is_dir( $src . '/' . $file ) ) {
                copy_directory( $src . '/' . $file, $dst . '/' . $file );
            } else {
                copy( $src . '/' . $file, $dst . '/' . $file );
            }
        }
    }
    closedir( $dir );
}

function delete_directory( string $dir ): bool {
    if ( ! is_dir( $dir ) ) {
        return false;
    }
    $files = array_diff( scandir( $dir ), [ '.', '..' ] );
    foreach ( $files as $file ) {
        ( is_dir( "$dir/$file" ) ) ? delete_directory( "$dir/$file" ) : unlink( "$dir/$file" );
    }
    return rmdir( $dir );
}

function generate_salts(): string {
    $keys = [
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT',
    ];

    $out = '';
    foreach ( $keys as $k ) {
        $rand = bin2hex( random_bytes( 32 ) );
        $out .= sprintf( "define( '%s', '%s' );\n", $k, $rand );
    }
    return $out;
}
