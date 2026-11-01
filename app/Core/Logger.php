<?php

declare(strict_types=1);

namespace App\Core;

/**
 * PSR-3-ish logger writing to storage/logs/<file>.log with daily rotation.
 */
final class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );

        @file_put_contents($dir . '/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $m, array $c = []): void  { self::log('info', $m, $c); }
    public static function warning(string $m, array $c = []): void { self::log('warning', $m, $c); }
    public static function error(string $m, array $c = []): void   { self::log('error', $m, $c); }
    public static function debug(string $m, array $c = []): void   { self::log('debug', $m, $c); }
    public static function critical(string $m, array $c = []): void { self::log('critical', $m, $c); }
}
