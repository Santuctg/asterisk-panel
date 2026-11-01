<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base model: maps a table, provides CRUD + a few query builders.
 * Deliberately small — controllers mostly use Database directly for
 * complex joins, and models for single-entity operations.
 */
abstract class Model
{
    protected static string $table = '';
    protected static string $primaryKey = 'id';

    public static function db(): Database
    {
        return App::getInstance()->db();
    }

    public static function table(): string
    {
        return static::$table;
    }

    public static function find(int $id): ?array
    {
        return static::db()->one(
            'SELECT * FROM ' . static::$table . ' WHERE ' . static::$primaryKey . ' = ?',
            [$id]
        );
    }

    public static function all(string $orderBy = 'id'): array
    {
        return static::db()->all('SELECT * FROM ' . static::$table . ' ORDER BY ' . $orderBy);
    }

    public static function where(string $where, array $params = [], string $orderBy = 'id'): array
    {
        return static::db()->all(
            'SELECT * FROM ' . static::$table . ' WHERE ' . $where . ' ORDER BY ' . $orderBy,
            $params
        );
    }

    public static function create(array $data): int
    {
        $data['created_at'] ??= date('Y-m-d H:i:s');
        return static::db()->insert(static::$table, $data);
    }

    public static function updateRow(int $id, array $data): int
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return static::db()->update(static::$table, $id, $data, static::$primaryKey);
    }

    public static function deleteRow(int $id): int
    {
        return static::db()->delete(static::$table, $id, static::$primaryKey);
    }

    public static function count(string $where = '1=1', array $params = []): int
    {
        return static::db()->count(static::$table, $where, $params);
    }

    /**
     * Paginated query. Returns ['rows' => [], 'total' => int, 'page' => int, 'pages' => int].
     */
    public static function paginate(int $page = 1, int $perPage = 25, string $where = '1=1', array $params = [], string $orderBy = 'id'): array
    {
        $db    = static::db();
        $total = (int) $db->scalar('SELECT COUNT(*) FROM ' . static::$table . ' WHERE ' . $where, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min(max(1, $page), $pages);
        $off   = ($page - 1) * $perPage;

        $rows = $db->all(
            'SELECT * FROM ' . static::$table . ' WHERE ' . $where . ' ORDER BY ' . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . $off,
            $params
        );

        return compact('rows', 'total', 'page', 'pages');
    }
}
