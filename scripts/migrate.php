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
$db->exec($schema['extension_status_history']);
$db->exec($schema['server_stats_history']);
$db->exec($schema['announcements']);
$db->exec($schema['outbound_route_schedules']);

// Outbound calling is denied by default and enabled per extension by assigning
// one tenant-scoped outbound route.
$driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

// Preserve existing outbound Time Condition assignments as route-local
// schedules. Direct schedules take precedence; the legacy relation remains
// readable until the route is next saved.
$hasTimeConditions = $driver === 'sqlite'
    ? (bool) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'time_conditions'")
    : (bool) $db->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'time_conditions'");
$hasTimeConditionLink = $driver === 'sqlite'
    ? in_array('time_condition_id', array_column($db->all('PRAGMA table_info(outbound_routes)'), 'name'), true)
    : (bool) $db->scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outbound_routes' AND COLUMN_NAME = 'time_condition_id'");
if ($hasTimeConditions && $hasTimeConditionLink) {
    $timeSql = $driver === 'sqlite'
        ? 'printf("%02d:%02d", tc.start_hour, COALESCE(tc.start_minute, 0)), printf("%02d:%02d", tc.end_hour, COALESCE(tc.end_minute, 0))'
        : 'CONCAT(LPAD(tc.start_hour, 2, "0"), ":", LPAD(COALESCE(tc.start_minute, 0), 2, "0")), CONCAT(LPAD(tc.end_hour, 2, "0"), ":", LPAD(COALESCE(tc.end_minute, 0), 2, "0"))';
    $db->run('INSERT INTO outbound_route_schedules
        (tenant_id, outbound_route_id, name, days_of_week, start_time, end_time, ordering, created_at)
        SELECT o.tenant_id, o.id, tc.name, COALESCE(NULLIF(tc.days_of_week, ""), "*"), '
        . $timeSql . ', 0, CURRENT_TIMESTAMP
        FROM outbound_routes o JOIN time_conditions tc ON tc.id = o.time_condition_id AND tc.tenant_id = o.tenant_id
        WHERE tc.start_hour IS NOT NULL AND tc.end_hour IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM outbound_route_schedules s WHERE s.outbound_route_id = o.id)');
}
if ($driver === 'sqlite') {
    $hasMohClasses = (int) $db->scalar(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'moh_classes'"
    );
    if (!$hasMohClasses) {
        $db->exec($schema['moh_classes']);
    } else {
        $mohColumns = array_column($db->all('PRAGMA table_info(moh_classes)'), 'name');
        if (!in_array('tenant_id', $mohColumns, true)) {
            $firstTenant = (int) $db->scalar('SELECT id FROM tenants ORDER BY id LIMIT 1');
            if ($firstTenant <= 0) { throw new RuntimeException('Cannot migrate Music on Hold without a tenant.'); }
            $mohRoot = rtrim((string) config('asterisk.moh_path', '/var/lib/asterisk/moh'), '/');
            $pdo->exec('PRAGMA foreign_keys = OFF');
            try {
                $pdo->beginTransaction();
                $pdo->exec('CREATE TABLE moh_classes_tenant_scoped (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                    name VARCHAR(80) NOT NULL, directory VARCHAR(255),
                    random INTEGER DEFAULT 1, shuffle INTEGER DEFAULT 0,
                    created_at DATETIME, updated_at DATETIME,
                    UNIQUE(tenant_id, name)
                )');
                $rows = $db->all('SELECT * FROM moh_classes ORDER BY id');
                $insert = $pdo->prepare('INSERT INTO moh_classes_tenant_scoped
                    (id, tenant_id, name, directory, random, shuffle, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                foreach ($rows as $row) {
                    $directory = trim((string) ($row['directory'] ?? ''));
                    if ($directory === '') { $directory = $mohRoot . '/' . $row['name']; }
                    $insert->execute([
                        $row['id'], $firstTenant, $row['name'], $directory,
                        $row['random'] ?? 1, $row['shuffle'] ?? 0,
                        $row['created_at'] ?? null, $row['updated_at'] ?? null,
                    ]);
                }
                $pdo->exec('DROP TABLE moh_classes');
                $pdo->exec('ALTER TABLE moh_classes_tenant_scoped RENAME TO moh_classes');
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $e;
            } finally {
                $pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
    }
    $hasExtensions = (int) $db->scalar(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'extensions'"
    );
    if ($hasExtensions) {
        $extensionColumns = array_column($db->all('PRAGMA table_info(extensions)'), 'name');
        if (!in_array('outbound_route_id', $extensionColumns, true)) {
            $pdo->exec('ALTER TABLE extensions ADD COLUMN outbound_route_id INTEGER');
        }
    }
    $hasOutboundRoutes = (int) $db->scalar(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'outbound_routes'"
    );
    if ($hasOutboundRoutes) {
        $routeColumns = array_column($db->all('PRAGMA table_info(outbound_routes)'), 'name');
        if (!in_array('time_condition_id', $routeColumns, true)) {
            $pdo->exec('ALTER TABLE outbound_routes ADD COLUMN time_condition_id INTEGER');
        }
    }
    foreach (['ring_groups', 'inbound_routes', 'queues'] as $table) {
        $exists = (int) $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        if (!$exists) { continue; }
        $columns = array_column($db->all("PRAGMA table_info({$table})"), 'name');
        if (!in_array('announcement_id', $columns, true)) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN announcement_id INTEGER");
        }
    }
    $announcementColumns = array_column($db->all('PRAGMA table_info(announcements)'), 'name');
    if (!in_array('dest_type', $announcementColumns, true)) {
        $pdo->exec('ALTER TABLE announcements ADD COLUMN dest_type VARCHAR(30) DEFAULT "hangup"');
    }
    if (!in_array('dest_id', $announcementColumns, true)) {
        $pdo->exec('ALTER TABLE announcements ADD COLUMN dest_id INTEGER');
    }
    if (!in_array('original_filename', $announcementColumns, true)) {
        $pdo->exec('ALTER TABLE announcements ADD COLUMN original_filename VARCHAR(255)');
    }
} else {
    $hasMohTenant = (int) $db->scalar(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['moh_classes', 'tenant_id']
    );
    if (!$hasMohTenant) {
        $firstTenant = (int) $db->scalar('SELECT id FROM tenants ORDER BY id LIMIT 1');
        if ($firstTenant <= 0) { throw new RuntimeException('Cannot migrate Music on Hold without a tenant.'); }
        $pdo->exec('ALTER TABLE moh_classes ADD COLUMN tenant_id BIGINT NULL');
        $db->run('UPDATE moh_classes SET tenant_id = ?', [$firstTenant]);
        foreach ($db->all("SHOW INDEX FROM moh_classes WHERE Non_unique = 0") as $index) {
            if (($index['Column_name'] ?? '') === 'name' && ($index['Key_name'] ?? '') !== 'PRIMARY') {
                $key = str_replace('`', '``', (string) $index['Key_name']);
                $pdo->exec("ALTER TABLE moh_classes DROP INDEX `{$key}`");
            }
        }
        $pdo->exec('ALTER TABLE moh_classes MODIFY tenant_id BIGINT NOT NULL');
        $pdo->exec('ALTER TABLE moh_classes ADD UNIQUE INDEX moh_classes_tenant_name_unique (tenant_id, name)');
    }
    $hasOutboundRoute = (int) $db->scalar(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['extensions', 'outbound_route_id']
    );
    if (!$hasOutboundRoute) {
        $pdo->exec('ALTER TABLE extensions ADD COLUMN outbound_route_id BIGINT NULL');
    }
    $hasTimeCondition = (int) $db->scalar(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        ['outbound_routes', 'time_condition_id']
    );
    if (!$hasTimeCondition) {
        $pdo->exec('ALTER TABLE outbound_routes ADD COLUMN time_condition_id BIGINT NULL');
    }
    foreach (['ring_groups', 'inbound_routes', 'queues'] as $table) {
        $hasAnnouncement = (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'announcement_id']
        );
        if (!$hasAnnouncement) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN announcement_id BIGINT NULL");
        }
    }
    foreach (['dest_type' => 'VARCHAR(30) DEFAULT "hangup"', 'dest_id' => 'BIGINT NULL', 'original_filename' => 'VARCHAR(255) NULL'] as $column => $definition) {
        $exists = (int) $db->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['announcements', $column]
        );
        if (!$exists) { $pdo->exec("ALTER TABLE announcements ADD COLUMN {$column} {$definition}"); }
    }
}

// Every tenant starts with a private default playlist. Existing global MOH
// rows were assigned to the first tenant above; this fills the remaining ones.
foreach ($db->all('SELECT id FROM tenants ORDER BY id') as $tenant) {
    $tenantId = (int) $tenant['id'];
    if (!$db->scalar('SELECT COUNT(*) FROM moh_classes WHERE tenant_id = ? AND name = ?', [$tenantId, 'default'])) {
        $db->insert('moh_classes', [
            'tenant_id' => $tenantId,
            'name' => 'default',
            'directory' => null,
            'random' => 1,
            'shuffle' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

// Route-specific dialplan contexts (for example default-outbound-1) still
// belong to the tenant whose base context is "default". Backfill previously
// imported CDRs so tenant users can see calls and their recordings.
foreach ($db->all('SELECT id, context FROM tenants ORDER BY LENGTH(context) DESC') as $tenant) {
    $rows = $db->all('SELECT id, uniqueid, dcontext FROM cdr WHERE tenant_id IS NULL');
    foreach ($rows as $row) {
        $dcontext = (string) $row['dcontext'];
        $context = (string) $tenant['context'];
        if ($dcontext !== $context && !str_starts_with($dcontext, $context . '-')) {
            continue;
        }
        $db->update('cdr', (int) $row['id'], ['tenant_id' => (int) $tenant['id']]);
        if (!empty($row['uniqueid'])) {
            $db->run('UPDATE cdr_import_log SET tenant_id = ? WHERE uniqueid = ?', [(int) $tenant['id'], $row['uniqueid']]);
        }
    }
}

// Keep a permanent import ledger so clearing call history does not cause old
// rows still present in Asterisk's Master.csv to be imported again.
if ($driver === 'sqlite') {
    $db->run('INSERT OR IGNORE INTO cdr_import_log (uniqueid, tenant_id, imported_at)
              SELECT uniqueid, tenant_id, COALESCE(calldate, CURRENT_TIMESTAMP)
              FROM cdr WHERE uniqueid IS NOT NULL AND uniqueid <> ""');
} else {
    $db->run('INSERT IGNORE INTO cdr_import_log (uniqueid, tenant_id, imported_at)
              SELECT uniqueid, tenant_id, COALESCE(calldate, CURRENT_TIMESTAMP)
              FROM cdr WHERE uniqueid IS NOT NULL AND uniqueid <> ""');
}

if ($driver === 'sqlite') {
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
