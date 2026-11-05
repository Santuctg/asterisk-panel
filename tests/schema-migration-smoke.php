<?php

declare(strict_types=1);

$base = dirname(__DIR__);
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
$legacy->exec('CREATE TABLE outbound_routes (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, name VARCHAR(120), match_pattern VARCHAR(60)
)');
$legacy->exec('CREATE TABLE ring_groups (id INTEGER PRIMARY KEY, tenant_id INTEGER, extension TEXT, name TEXT)');
$legacy->exec('CREATE TABLE inbound_routes (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT)');
$legacy->exec('CREATE TABLE queues (id INTEGER PRIMARY KEY, tenant_id INTEGER, extension TEXT, name TEXT)');
$legacy->exec("INSERT INTO tenants(name,context,enabled) VALUES ('A','a',1),('B','b',1)");
$legacy->exec("INSERT INTO users(tenant_id,username,password,role,active) VALUES (1,'admin','x','superadmin',1)");
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
if (!in_array('outbound_route_id', array_column($db->all('PRAGMA table_info(extensions)'), 'name'), true)) throw new RuntimeException('Extension outbound route column missing');
if (!in_array('time_condition_id', array_column($db->all('PRAGMA table_info(outbound_routes)'), 'name'), true)) throw new RuntimeException('Outbound route time condition column missing');
if ((int)$db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='announcements'") !== 1) throw new RuntimeException('Announcements table missing');
foreach (['ring_groups','inbound_routes','queues'] as $table) {
    if (!in_array('announcement_id', array_column($db->all("PRAGMA table_info({$table})"), 'name'), true)) throw new RuntimeException("Announcement column missing from {$table}");
}
// Subsequent migrations must ignore the partial global-superadmin index.
$migration = file_get_contents($base . '/scripts/migrate.php');
if (!str_contains($migration, "index['partial']")) throw new RuntimeException('Partial-index idempotence guard missing');
unset($db);
@unlink($path);
echo "All schema migration checks passed.\n";
