<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Tiny PSR-4 style autoloader so the app runs without Composer.
 * Maps the `App\` namespace onto the `app/` directory.
 */
final class Autoloader
{
    private static string $base = '';

    public static function register(string $basePath): void
    {
        self::$base = rtrim($basePath, '/\\');
        spl_autoload_register([self::class, 'load']);
    }

    public static function load(string $class): void
    {
        if (!str_starts_with($class, 'App\\')) {
            return;
        }

        $relative = substr($class, 4); // strip "App\"
        $path     = self::$base . '/app/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require $path;
        }
    }
}
