<?php

declare(strict_types=1);

namespace App\Core;

/**
 * PDO wrapper with light query helpers. Default driver is SQLite
 * (zero-config), but resources/config/database.php can switch to MySQL.
 */
final class Database
{
    private ?\PDO $pdo = null;

    public function pdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        $driver = Config::get('database.driver', 'sqlite');
        $options = [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($driver === 'sqlite') {
            $path = Config::get('database.database', BASE_PATH . '/storage/database/asterisk_panel.sqlite');
            @mkdir(dirname($path), 0775, true);
            $dsn = 'sqlite:' . $path;
            $this->pdo = new \PDO($dsn, null, null, $options);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        } else {
            $host = Config::get('database.host', '127.0.0.1');
            $port = Config::get('database.port', 3306);
            $name = Config::get('database.database', 'asterisk_panel');
            $dsn  = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $this->pdo = new \PDO($dsn, Config::get('database.username'), Config::get('database.password', ''), $options);
        }

        return $this->pdo;
    }

    /** Run a statement and return the PDOStatement. */
    public function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Fetch all rows. */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** Fetch one row or null. */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Fetch a single scalar column from the first row. */
    public function scalar(string $sql, array $params = []): mixed
    {
        return $this->run($sql, $params)->fetchColumn();
    }

    /** Insert and return the new primary key. */
    public function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        $this->run($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    public function update(string $table, int|string $id, array $data, string $pk = 'id'): int
    {
        if (!$data) {
            return 0;
        }
        $sets = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys($data)));
        $sql  = "UPDATE {$table} SET {$sets} WHERE {$pk} = :__pk";
        $data['__pk'] = $id;
        return $this->run($sql, $data)->rowCount();
    }

    public function delete(string $table, int|string $id, string $pk = 'id'): int
    {
        return $this->run("DELETE FROM {$table} WHERE {$pk} = :id", ['id' => $id])->rowCount();
    }

    public function count(string $table, string $where = '1=1', array $params = []): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
    }

    /** Run several statements separated by ";". Used by schema migrations. */
    public function exec(string $sql): void
    {
        $this->pdo()->exec($sql);
    }
}
