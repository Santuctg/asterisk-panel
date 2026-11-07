<?php

declare(strict_types=1);

namespace App\Core;

/** Converts the canonical SQLite-friendly schema DDL for MySQL/MariaDB. */
final class SchemaSql
{
    public static function compile(string $sql, string $driver): string
    {
        if (strtolower($driver) !== 'mysql') return $sql;

        $sql = str_replace('AUTOINCREMENT', 'AUTO_INCREMENT', $sql);
        $sql = preg_replace_callback(
            '/DEFAULT\s+"([^"]*)"/i',
            static fn (array $match): string => "DEFAULT '" . str_replace("'", "''", $match[1]) . "'",
            $sql
        ) ?? $sql;

        // Older MySQL/MariaDB versions reject defaults on TEXT columns.
        $sql = preg_replace("/(permissions\s+TEXT\s+NOT\s+NULL)\s+DEFAULT\s+'\[\]'/i", '$1', $sql) ?? $sql;
        return $sql;
    }
}
