<?php

declare(strict_types=1);

$base = dirname(__DIR__);
require_once $base . '/app/Core/SchemaSql.php';
$schema = require $base . '/resources/config/schema.php';
$mysqlRoles = App\Core\SchemaSql::compile($schema['roles'], 'mysql');
$mysqlSettings = App\Core\SchemaSql::compile($schema['settings'], 'mysql');
foreach ($schema as $table => $ddl) {
    $mysqlDdl = App\Core\SchemaSql::compile($ddl, 'mysql');
    if (str_contains($mysqlDdl, 'AUTOINCREMENT') || str_contains($mysqlDdl, 'DEFAULT "')) {
        throw new RuntimeException("MySQL schema compilation left SQLite-only syntax in {$table}");
    }
}
if (!str_contains($mysqlRoles, 'AUTO_INCREMENT')
    || preg_match("/permissions\\s+TEXT[^,]*DEFAULT/i", $mysqlRoles)) {
    throw new RuntimeException('MySQL schema compilation did not normalize identity or default syntax');
}
if (!str_contains($mysqlSettings, '`key`') || !str_contains($mysqlSettings, 'UNIQUE(tenant_id, `key`)')) {
    throw new RuntimeException('MySQL settings schema does not quote the reserved key column');
}
if (App\Core\SchemaSql::compile($schema['roles'], 'sqlite') !== $schema['roles']) {
    throw new RuntimeException('SQLite schema compilation must preserve canonical DDL');
}
$path = $base . '/storage/cache/schema-migration-smoke.sqlite';
@mkdir(dirname($path), 0775, true);
@unlink($path);
$legacy = new PDO('sqlite:' . $path);
$legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacy->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, context TEXT, enabled INTEGER)');
$legacy->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(160), password VARCHAR(255) NOT NULL, role VARCHAR(20), active INTEGER,
    last_login DATETIME, last_login_ip VARCHAR(45), created_at DATETIME, updated_at DATETIME
)');
$legacy->exec('CREATE TABLE extensions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, extension VARCHAR(20), name VARCHAR(120), secret VARCHAR(128)
)');
$legacy->exec('CREATE TABLE devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, extension_id INTEGER,
    mac VARCHAR(32) NOT NULL UNIQUE, vendor VARCHAR(40), enabled INTEGER, created_at DATETIME, updated_at DATETIME
)');
$legacy->exec('CREATE TABLE outbound_routes (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name VARCHAR(120), match_pattern VARCHAR(60)
)');
$legacy->exec('CREATE TABLE ring_groups (id INTEGER PRIMARY KEY, tenant_id INTEGER, extension TEXT, name TEXT)');
$legacy->exec('CREATE TABLE inbound_routes (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT)');
$legacy->exec('CREATE TABLE queues (id INTEGER PRIMARY KEY, tenant_id INTEGER, extension TEXT, name TEXT)');
$legacy->exec('CREATE TABLE settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, key VARCHAR(80), value TEXT,
    created_at DATETIME, updated_at DATETIME
)');
$legacy->exec('CREATE TABLE moh_classes (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(80) NOT NULL UNIQUE, directory VARCHAR(255),
    random INTEGER DEFAULT 1, shuffle INTEGER DEFAULT 0, created_at DATETIME, updated_at DATETIME
)');
$legacy->exec("INSERT INTO moh_classes (name, random) VALUES ('default', 1)");
$legacy->exec("INSERT INTO tenants(name,context,enabled) VALUES ('A','a',1),('B','b',1)");
$legacy->exec("INSERT INTO users(tenant_id,username,password,role,active) VALUES (1,'admin','x','superadmin',1)");
$legacy->exec("INSERT INTO devices(tenant_id,mac,vendor,enabled) VALUES (1,'001565aabbcc','generic',1)");
$legacy->exec("INSERT INTO settings(tenant_id,key,value) VALUES (NULL,'company_name','old-global'),(NULL,'company_name','new-global'),(1,'company_name','old-tenant'),(1,'company_name','new-tenant')");
$legacy = null;

putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=' . $path);
require $base . '/scripts/migrate.php';

$db = App\Core\App::getInstance()->db();
$super = $db->one("SELECT * FROM users WHERE role = 'superadmin'");
if ($super === null || $super['tenant_id'] !== null) throw new RuntimeException('Superadmin was not normalized');
$db->insert('users', ['tenant_id'=>1,'username'=>'same','password'=>'x','role'=>'user','active'=>1]);
$db->insert('users', ['tenant_id'=>2,'username'=>'same','password'=>'x','role'=>'user','active'=>1]);
if ((int)$db->scalar("SELECT COUNT(*) FROM users WHERE username='same'") !== 2) throw new RuntimeException('Tenant username uniqueness migration failed');
if ((int)$db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='roles'") !== 1) throw new RuntimeException('Roles table missing');
if ((int)$db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='tenant_login_bans'") !== 1) throw new RuntimeException('Tenant login bans table missing');
if ((int)$db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='cdr_import_log'") !== 1) throw new RuntimeException('CDR import ledger table missing');
if ((int)$db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='trunk_status_history'") !== 1) throw new RuntimeException('Trunk status history table missing');
if ((int)$db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='extension_status_history'") !== 1) throw new RuntimeException('Extension status history table missing');
if ((int)$db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='server_stats_history'") !== 1) throw new RuntimeException('Server stats history table missing');
if ((int)$db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='webphone_login_throttles'") !== 1) throw new RuntimeException('Web Phone throttle table missing');
if (!in_array('tenant_id', array_column($db->all('PRAGMA table_info(audit_log)'), 'name'), true)) throw new RuntimeException('Audit tenant column missing');
if (!in_array('outbound_route_id', array_column($db->all('PRAGMA table_info(extensions)'), 'name'), true)) throw new RuntimeException('Extension outbound route column missing');
if (!in_array('provision_token', array_column($db->all('PRAGMA table_info(devices)'), 'name'), true)) throw new RuntimeException('Device provisioning token column missing');
if (strlen((string) $db->scalar('SELECT provision_token FROM devices WHERE id = 1')) !== 64) throw new RuntimeException('Legacy device provisioning token was not backfilled');
if ((int) $db->scalar("SELECT COUNT(*) FROM settings WHERE key = 'company_name'") !== 2
    || (string) $db->scalar("SELECT value FROM settings WHERE tenant_id IS NULL AND key = 'company_name'") !== 'new-global'
    || (string) $db->scalar("SELECT value FROM settings WHERE tenant_id = 1 AND key = 'company_name'") !== 'new-tenant') throw new RuntimeException('Settings deduplication or precedence migration failed');
if (!in_array('time_condition_id', array_column($db->all('PRAGMA table_info(outbound_routes)'), 'name'), true)) throw new RuntimeException('Outbound route time condition column missing');
if ((int)$db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='announcements'") !== 1) throw new RuntimeException('Announcements table missing');
if ((int)$db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='outbound_route_schedules'") !== 1) throw new RuntimeException('Outbound route schedules table missing');
$announcementColumns = array_column($db->all('PRAGMA table_info(announcements)'), 'name');
if (!in_array('dest_type', $announcementColumns, true) || !in_array('dest_id', $announcementColumns, true)
    || !in_array('original_filename', $announcementColumns, true)) throw new RuntimeException('Announcement destination or original filename columns missing');
foreach (['ring_groups','inbound_routes','queues'] as $table) {
    if (!in_array('announcement_id', array_column($db->all("PRAGMA table_info({$table})"), 'name'), true)) throw new RuntimeException("Announcement column missing from {$table}");
}
$mohColumns = array_column($db->all('PRAGMA table_info(moh_classes)'), 'name');
if (!in_array('tenant_id', $mohColumns, true)) throw new RuntimeException('MOH tenant column missing');
if ((int) $db->scalar('SELECT tenant_id FROM moh_classes WHERE name = ?', ['default']) !== 1) throw new RuntimeException('Legacy MOH class was not tenant backfilled');
if ((string) $db->scalar('SELECT directory FROM moh_classes WHERE name = ?', ['default']) === '') throw new RuntimeException('Legacy MOH directory was not preserved');
// Subsequent migrations must ignore the partial global-superadmin index.
$migration = file_get_contents($base . '/scripts/migrate.php');
if (!str_contains($migration, "index['partial']")) throw new RuntimeException('Partial-index idempotence guard missing');
unset($db);
@unlink($path);
echo "All schema migration checks passed.\n";
