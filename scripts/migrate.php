<?php

declare(strict_types=1);

/** Idempotent production schema upgrades. */
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
$pdo = $db->pdo();

// New tables are always safe to ensure.
$schema = require BASE_PATH . '/resources/config/schema.php';
$db->exec($schema['roles']);
$db->exec($schema['tenant_login_bans']);
$db->exec($schema['cdr']);
$db->exec($schema['cdr_import_log']);
$db->exec($schema['trunk_status_history']);

// Keep a permanent import ledger so clearing call history does not cause old
// rows still present in Asterisk's Master.csv to be imported again.
if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $db->run('INSERT OR IGNORE INTO cdr_import_log (uniqueid, tenant_id, imported_at)
              SELECT uniqueid, tenant_id, COALESCE(calldate, CURRENT_TIMESTAMP)
              FROM cdr WHERE uniqueid IS NOT NULL AND uniqueid <> ""');
} else {
    $db->run('INSERT IGNORE INTO cdr_import_log (uniqueid, tenant_id, imported_at)
              SELECT uniqueid, tenant_id, COALESCE(calldate, CURRENT_TIMESTAMP)
              FROM cdr WHERE uniqueid IS NOT NULL AND uniqueid <> ""');
}

if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $hasGlobalUsernameUnique = false;
    foreach ($db->all('PRAGMA index_list(users)') as $index) {
        if (!(int) ($index['unique'] ?? 0) || (int) ($index['partial'] ?? 0) === 1) { continue; }
        $columns = array_column($db->all('PRAGMA index_info(' . $index['name'] . ')'), 'name');
        if ($columns === ['username']) { $hasGlobalUsernameUnique = true; break; }
    }

    if ($hasGlobalUsernameUnique) {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $pdo->beginTransaction();
            $pdo->exec('CREATE TABLE users_tenant_scoped (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER REFERENCES tenants(id) ON DELETE CASCADE,
                username VARCHAR(80) NOT NULL,
                email VARCHAR(160), password VARCHAR(255) NOT NULL,
                role VARCHAR(20) DEFAULT "user", active INTEGER DEFAULT 1,
                last_login DATETIME, last_login_ip VARCHAR(45),
                created_at DATETIME, updated_at DATETIME,
                UNIQUE(tenant_id, username)
            )');
            $pdo->exec('INSERT INTO users_tenant_scoped
                (id, tenant_id, username, email, password, role, active, last_login, last_login_ip, created_at, updated_at)
                SELECT id, CASE WHEN role = "superadmin" THEN NULL ELSE tenant_id END,
                       username, email, password, role, active, last_login, last_login_ip, created_at, updated_at
                FROM users');
            $pdo->exec('DROP TABLE users');
            $pdo->exec('ALTER TABLE users_tenant_scoped RENAME TO users');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS users_global_username_unique
                ON users(username) WHERE tenant_id IS NULL');
} else {
    // Fresh MySQL installs already use the tenant-scoped schema. Existing
    // deployments should remove their legacy single-column username index
    // before running this migration.
    $db->run("UPDATE users SET tenant_id = NULL WHERE role = 'superadmin'");
}

echo "Database migrations completed.\n";
