<?php

declare(strict_types=1);

/**
 * Installer: creates the database schema and the first admin account.
 *
 *   php scripts/install.php                # interactive-ish defaults
 *   php scripts/install.php --username=admin --password=secret \
 *        --email=admin@example.com --tenant="Acme Corp"
 *
 * Safe to re-run: it uses CREATE TABLE IF NOT EXISTS and skips creating an
 * admin if one already exists.
 */

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');

require BASE_PATH . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Config;
use App\Core\Database;

$app = App::getInstance();
$app->boot();

$db = App::getInstance()->db();

$opts = getopt('', ['username::', 'password::', 'email::', 'tenant::', 'context::', 'force']);

echo "=== Asterisk Panel installer ===\n\n";

// ---------------------------------------------------------------------
// 1. Create storage directories
// ---------------------------------------------------------------------
echo "[1/4] Creating storage directories...\n";
foreach (['storage/database', 'storage/logs', 'storage/cache', 'storage/provision', 'storage/asterisk'] as $dir) {
    $path = BASE_PATH . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
        echo "      + {$dir}\n";
    }
}

// ---------------------------------------------------------------------
// 2. Run schema
// ---------------------------------------------------------------------
echo "[2/4] Creating database tables...\n";
$schema = require BASE_PATH . '/resources/config/schema.php';

$tables = [];
foreach ($schema as $name => $sql) {
    $db->exec($sql);
    $tables[] = $name;
}
echo '      ' . count($tables) . " tables ensured\n";
$db->run("UPDATE users SET tenant_id = NULL WHERE role = 'superadmin'");

// ---------------------------------------------------------------------
// 2b. Migrate existing tables: add `record` column where missing.
//     Safe to re-run: each ALTER is skipped when the column already exists.
// ---------------------------------------------------------------------
function addColumnIfMissing(\App\Core\Database $db, string $table, string $column, string $def): void
{
    try {
        $cols = $db->all("PRAGMA table_info({$table})");
    } catch (\Throwable $e) {
        return; // not SQLite; schema already handles fresh installs
    }
    $names = array_column($cols, 'name');
    if (!in_array($column, $names, true)) {
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$def}");
    }
}

foreach ([
    ['extensions',         'record', 'INTEGER DEFAULT 0'],
    ['ring_groups',        'record', 'INTEGER DEFAULT 0'],
    ['ivr_menus',          'record', 'INTEGER DEFAULT 0'],
    ['inbound_routes',     'record', 'INTEGER DEFAULT 0'],
    ['outbound_routes',    'record', 'INTEGER DEFAULT 0'],
    ['time_conditions',    'record', 'INTEGER DEFAULT 0'],
    ['queues',             'record', 'INTEGER DEFAULT 0'],
] as [$table, $column, $def]) {
    addColumnIfMissing($db, $table, $column, $def);
}
echo "      `record` column ensured on call-routable tables\n";

// ---------------------------------------------------------------------
// 3. Create the default tenant
// ---------------------------------------------------------------------
echo "[3/4] Setting up tenant...\n";

$tenantName  = $opts['tenant'] ?? 'Default Business';
$tenantCtx   = $opts['context'] ?? 'default';

$existingTenant = $db->one('SELECT id FROM tenants WHERE context = ?', [$tenantCtx]);

if ($existingTenant) {
    $tenantId = (int) $existingTenant['id'];
    echo "      Tenant already exists (id {$tenantId}, context {$tenantCtx})\n";
} else {
    $tenantId = $db->insert('tenants', [
        'name'       => $tenantName,
        'context'    => $tenantCtx,
        'domain'     => $tenantCtx === 'default' ? null : $tenantCtx,
        'timezone'   => 'America/New_York',
        'enabled'    => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    echo "      Created tenant \"{$tenantName}\" (context {$tenantCtx})\n";
}

// Seed a sensible default MOH class.
if (!$db->scalar("SELECT COUNT(*) FROM moh_classes WHERE name = 'default'")) {
    $db->insert('moh_classes', [
        'name'       => 'default',
        'directory'  => null,
        'random'     => 1,
        'shuffle'    => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    echo "      + default music-on-hold class\n";
}

// ---------------------------------------------------------------------
// 4. Create the admin user
// ---------------------------------------------------------------------
echo "[4/4] Creating admin user...\n";

$username = $opts['username'] ?? 'admin';
$password = $opts['password'] ?? null;
$email    = $opts['email'] ?? null;

$existingUser = $db->one('SELECT id FROM users WHERE username = ?', [$username]);

if ($existingUser && !array_key_exists('force', $opts)) {
    echo "      User \"{$username}\" already exists — skipping.\n";
} else {
    if ($password === null) {
        // Generate one and print it so the operator can sign in immediately.
        $password = bin2hex(random_bytes(6));
        echo "      No password given; generated one.\n";
    }

    $data = [
        'username'   => $username,
        'email'      => $email,
        'password'   => password_hash($password, PASSWORD_DEFAULT),
        'role'       => 'superadmin',
        'tenant_id'  => $tenantId,
        'active'     => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ];

    if ($existingUser) {
        $db->update('users', (int) $existingUser['id'], $data);
        echo "      Updated user \"{$username}\"\n";
    } else {
        $db->insert('users', $data);
        echo "      Created user \"{$username}\"\n";
    }

    echo "\n      Sign-in credentials:\n";
    echo "        username: {$username}\n";
    echo "        password: {$password}\n";
}

// ---------------------------------------------------------------------
echo "\n=== Done ===\n\n";
echo "Next steps:\n";
echo "  1. Start the app:  php -S 0.0.0.0:8080 -t public public/router.php\n";
echo "  2. Sign in at      http://localhost:8080/login\n";
echo "  3. Wire up Asterisk (AMI + config #include): see docs/asterisk-setup.md\n";
echo "  4. Set APP_URL and AMI_* in your environment before production use.\n";
echo "  5. Enable HTTPS: sudo bash scripts/setup-https.sh --domain pbx.example.com --email admin@example.com\n";
echo "\nFor a new Ubuntu 24.04 production server, scripts/install-server-ubuntu.sh installs the panel and Asterisk together.\n";
