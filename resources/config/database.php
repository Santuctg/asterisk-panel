<?php

declare(strict_types=1);

/**
 * Database connection.
 *
 * Default is SQLite for zero-config installs. Set DB_DRIVER=mysql (plus
 * DB_HOST / DB_NAME / DB_USER / DB_PASS) to use MySQL/MariaDB.
 */

$env = static fn (string $key, mixed $default = null): mixed => getenv($key) !== false ? getenv($key) : $default;

return [
    'driver'   => $env('DB_DRIVER', 'sqlite'),
    'host'     => $env('DB_HOST', '127.0.0.1'),
    'port'     => (int) $env('DB_PORT', 3306),
    'database' => $env('DB_NAME', dirname(__DIR__, 2) . '/storage/database/asterisk_panel.sqlite'),
    'username' => $env('DB_USER', 'asterisk'),
    'password' => $env('DB_PASS', ''),
    'prefix'   => $env('DB_PREFIX', ''),
];
