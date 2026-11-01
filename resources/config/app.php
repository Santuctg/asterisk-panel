<?php

declare(strict_types=1);

/**
 * Core application settings.
 *
 * Every value can be overridden by an environment variable of the same name
 * (uppercase, dots -> underscores) so the same code runs in dev and prod.
 *
 *   APP_URL=https://pbx.example.com APP_DEBUG=1 php -S ...
 */

$env = static fn (string $key, mixed $default = null): mixed => getenv($key) !== false ? getenv($key) : $default;
$envBool = static function (string $key, bool $default = false): bool {
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
};
$appUrl = (string) $env('APP_URL', 'http://localhost:8080');

return [
    'name'    => $env('APP_NAME', 'Asterisk Panel'),
    'version' => '1.2.0',

    // Absolute, externally reachable URL of this panel. Used to build
    // provisioning URLs handed to phones.
    'url'     => $appUrl,

    'debug'   => $envBool('APP_DEBUG', false),

    'timezone' => $env('APP_TIMEZONE', 'UTC'),
    'locale'   => $env('APP_LOCALE', 'en_US.UTF-8'),

    'theme'   => $env('APP_THEME', 'default'),

    'session_name'      => $env('APP_SESSION_NAME', 'asterisk_panel_session'),
    // Defaults to secure whenever APP_URL is HTTPS; the environment variable
    // remains available as an explicit override for unusual proxy setups.
    'secure_cookies'    => $envBool('APP_SECURE_COOKIES', str_starts_with(strtolower($appUrl), 'https://')),

    // PBX-wide defaults applied to new tenants unless overridden.
    'defaults' => [
        'extension_range' => [1000, 1999],
        'ring_timeout'    => 25,
        'vm_pin_length'   => 6,
    ],
];
