<?php
/**
 * Router script for the PHP built-in web server.
 * This ensures clean URLs and proper routing for WordPress.
 */

$root = $_SERVER['DOCUMENT_ROOT'];
chdir($root);
$path = '/' . ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

if (file_exists($root . $path)) {
    if (is_dir($root . $path) && substr($path, strlen($path) - 1, 1) !== '/') {
        $path = rtrim($path, '/') . '/index.php';
    }
    if (strpos($path, '.php') === false) {
        // Let the built-in server handle static files directly
        return false;
    } else {
        chdir(dirname($root . $path));
        require_once $root . $path;
    }
} else {
    // Route everything else through index.php (WordPress front controller)
    include_once 'index.php';
}
