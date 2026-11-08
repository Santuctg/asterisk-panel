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
    'version' => '1.3.76',
    'github_repo' => $env('APP_GITHUB_REPO', 'https://github.com/Santuctg/asterisk-panel'),

    // Absolute, externally reachable URL of this panel. Used to build
    // provisioning URLs handed to phones.
    'url'     => $appUrl,
    // Stable main login hostname. Unlike APP_URL it is not replaced by a
    // tenant virtual host's FastCGI parameter.
    'primary_host' => strtolower((string) $env('APP_PRIMARY_HOST', parse_url($appUrl, PHP_URL_HOST) ?: '')),

    'debug'   => $envBool('APP_DEBUG', false),

    'timezone' => $env('APP_TIMEZONE', 'UTC'),
    'locale'   => $env('APP_LOCALE', 'en_US.UTF-8'),

    'theme'   => $env('APP_THEME', 'default'),

    'session_name'      => $env('APP_SESSION_NAME', 'asterisk_panel_session'),
    // Defaults to secure whenever APP_URL is HTTPS; the environment variable
    // remains available as an explicit override for unusual proxy setups.
    'secure_cookies'    => $envBool('APP_SECURE_COOKIES', str_starts_with(strtolower($appUrl), 'https://')),
    'session_timeout'   => max(900, (int) $env('APP_SESSION_TIMEOUT', 28800)),

    // PBX-wide defaults applied to new tenants unless overridden.
    'defaults' => [
        'extension_range' => [1000, 1999],
        'ring_timeout'    => 25,
        'vm_pin_length'   => 6,
    ],
];
