<?php
/**
 * Built-in PHP Server Router for WordPress Local Dev Environment.
 *
 * Directs static file requests directly to the file system with correct MIME types,
 * and routes WordPress admin, AJAX, REST API, and front-end requests to index.php.
 */

declare(strict_types=1);

$root = dirname(__DIR__) . '/wordpress';
$path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
$file = $root . $path;

// Serve existing files directly
if ( $path !== '/' && file_exists( $file ) && ! is_dir( $file ) ) {
    $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
    
    // Set appropriate MIME types for static assets
    $mimes = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'json'  => 'application/json',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'eot'   => 'application/vnd.ms-fontobject',
        'mp3'   => 'audio/mpeg',
        'wav'   => 'audio/wav',
        'pdf'   => 'application/pdf',
    ];

    if ( isset( $mimes[ $ext ] ) ) {
        header( 'Content-Type: ' . $mimes[ $ext ] );
        readfile( $file );
        return true;
    }

    // Direct PHP script executions (e.g. wp-login.php, wp-admin/admin-ajax.php, wp-cron.php)
    if ( $ext === 'php' ) {
        $_SERVER['SCRIPT_FILENAME'] = $file;
        $_SERVER['SCRIPT_NAME']     = $path;
        $_SERVER['PHP_SELF']        = $path;
        chdir( dirname( $file ) );
        require $file;
        return true;
    }

    return false;
}

// Route directory requests with index.php (e.g. /wp-admin/)
if ( is_dir( $file ) && file_exists( rtrim( $file, '/' ) . '/index.php' ) ) {
    $index_file = rtrim( $file, '/' ) . '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $index_file;
    $_SERVER['SCRIPT_NAME']     = rtrim( $path, '/' ) . '/index.php';
    $_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
    chdir( dirname( $index_file ) );
    require $index_file;
    return true;
}

// Route everything else through root index.php for WordPress rewrite rules & REST API
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
chdir( $root );
require $root . '/index.php';
return true;
