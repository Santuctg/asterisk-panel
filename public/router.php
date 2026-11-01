<?php
/**
 * Router script for PHP's built-in web server (development only).
 *
 *   php -S 0.0.0.0:8080 -t public public/router.php
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file  = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false; // Let the built-in server stream the static asset.
}

require __DIR__ . '/index.php';
