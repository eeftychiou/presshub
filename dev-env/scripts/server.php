<?php
/**
 * Local Development Web Server Runner.
 *
 * Launches PHP built-in webserver on 127.0.0.1:8888 with router.php.
 */

declare(strict_types=1);

$dev_env_dir = dirname(__DIR__);
$wp_dir      = $dev_env_dir . '/wordpress';
$router_file = __DIR__ . '/router.php';

$host = '127.0.0.1';
$port = 8888;

if ( isset( $argv[1] ) && is_numeric( $argv[1] ) ) {
    $port = (int) $argv[1];
}

echo "=================================================================\n";
echo "Starting PressHub AI WordPress Dev Server\n";
echo "=================================================================\n";
echo "URL:            http://{$host}:{$port}\n";
echo "Admin URL:      http://{$host}:{$port}/wp-admin/\n";
echo "Login:          admin / password123\n";
echo "REST API:       http://{$host}:{$port}/wp-json/presshub-ai/v1/\n";
echo "Document Root:  {$wp_dir}\n";
echo "Router:         {$router_file}\n";
echo "Press Ctrl+C to stop the server.\n";
echo "=================================================================\n\n";

$cmd = sprintf(
    '%s -S %s:%d -t %s %s',
    escapeshellarg( PHP_BINARY ),
    $host,
    $port,
    escapeshellarg( $wp_dir ),
    escapeshellarg( $router_file )
);

passthru( $cmd );
