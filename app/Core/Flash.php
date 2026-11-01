<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Persistent ("sticky") flash messages that survive one redirect and are
 * rendered by themes/default/partials/flash.php.
 */
final class Flash
{
    private const KEY = '__flash_messages';

    public static function add(string $type, string $message): void
    {
        $_SESSION[self::KEY][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $m): void
    {
        self::add('success', $m);
    }

    public static function error(string $m): void
    {
        self::add('error', $m);
    }

    public static function warning(string $m): void
    {
        self::add('warning', $m);
    }

    public static function info(string $m): void
    {
        self::add('info', $m);
    }

    /** Fetch all pending messages and clear them. */
    public static function pull(): array
    {
        $all = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);
        return $all;
    }
}
