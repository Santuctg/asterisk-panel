<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Loads every PHP file in a config directory and merges them into one
 * flat array keyed by filename. Dot-notation access: Config::get('db.host').
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    private static string $path = '';

    public static function load(string $dir): void
    {
        self::$path = rtrim($dir, '/\\');

        foreach (glob(self::$path . '/*.php') ?: [] as $file) {
            $key                = basename($file, '.php');
            $value              = require $file;
            self::$items[$key] = is_array($value) ? self::dot($value, $key) : $value;
        }
    }

    /** Recursively flatten nested config arrays for dot access. */
    private static function dot(array $arr, string $prefix): array
    {
        $out = [];
        foreach ($arr as $k => $v) {
            $key = $prefix . '.' . $k;
            if (is_array($v)) {
                $out += self::dot($v, $key);
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$items[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$items[$key] = $value;
    }

    public static function all(): array
    {
        return self::$items;
    }

    public static function path(string $sub = ''): string
    {
        return self::$path . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
    }
}
