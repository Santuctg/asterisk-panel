<?php

declare(strict_types=1);

// Run: php tests/tenant-auth-smoke.php (uses only an in-memory SQLite DB).
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('APP_URL=https://panel.example.test');
putenv('APP_PRIMARY_HOST=panel.example.test');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) {
    $db->exec($sql);
}

$tenantA = $db->insert('tenants', ['name' => 'Tenant A', 'context' => 'tenant_a', 'domain' => 'a.example.test', 'enabled' => 1]);
$tenantB = $db->insert('tenants', ['name' => 'Tenant B', 'context' => 'tenant_b', 'domain' => 'b.example.test', 'enabled' => 1]);
$tenantNoDomain = $db->insert('tenants', ['name' => 'Legacy', 'context' => 'legacy', 'domain' => null, 'enabled' => 1]);
$password = password_hash('correct-horse', PASSWORD_DEFAULT);
$super = $db->insert('users', ['tenant_id' => null, 'username' => 'rootadmin', 'password' => $password, 'role' => 'superadmin', 'active' => 1]);
$adminA = $db->insert('users', ['tenant_id' => $tenantA, 'username' => 'admin-a', 'password' => $password, 'role' => 'admin', 'active' => 1]);
$adminB = $db->insert('users', ['tenant_id' => $tenantB, 'username' => 'admin-b', 'password' => $password, 'role' => 'admin', 'active' => 1]);
$legacy = $db->insert('users', ['tenant_id' => $tenantNoDomain, 'username' => 'legacy-user', 'password' => $password, 'role' => 'user', 'active' => 1]);

$check = static function (bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . PHP_EOL;
};
$allows = static function (string $host, int $userId) use ($db): bool {
    $_SERVER['HTTP_HOST'] = $host;
    return App\Services\TenantHost::allows($db->one('SELECT * FROM users WHERE id = ?', [$userId]));
};

$check($allows('a.example.test', $adminA), 'tenant A user may use tenant A domain');
$check(!$allows('b.example.test', $adminA), 'tenant A user cannot use tenant B domain');
$check(!$allows('panel.example.test', $adminA), 'domain-bound tenant user cannot use main domain');
$check(!$allows('a.example.test', $super), 'super admin cannot use tenant domain');
$check($allows('panel.example.test', $super), 'super admin may use main domain');
$check($allows('panel.example.test', $legacy), 'domain-less tenant user may use main domain');
$check(!$allows('unknown.example.test', $super), 'unknown host fails closed');

$_SERVER['HTTP_HOST'] = 'a.example.test';
$check((new App\Core\Auth())->attempt('admin-a', 'correct-horse'), 'matching tenant login succeeds');
$_SERVER['HTTP_HOST'] = 'b.example.test';
$check(!(new App\Core\Auth())->attempt('admin-a', 'correct-horse'), 'cross-tenant login is rejected');
$_SERVER['HTTP_HOST'] = 'panel.example.test';
$check(!(new App\Core\Auth())->attempt('admin-a', 'correct-horse'), 'tenant login on main host is rejected');

// Role assignment is server-side scoped even when a tenant admin crafts POST data.
$_SERVER['HTTP_HOST'] = 'a.example.test';
$app->auth()->login($adminA);
$controller = new App\Controllers\UserController();
$controller->store(new App\Core\Request('POST', '/users', [], [
    'tenant_id' => (string) $tenantB, 'username' => 'scoped-user', 'email' => '',
    'role' => 'admin', 'password' => 'new-password', 'enabled' => '1',
]));
$created = $db->one('SELECT * FROM users WHERE username = ?', ['scoped-user']);
$check($created !== null && (int) $created['tenant_id'] === $tenantA, 'tenant admin cannot assign another tenant');

$controller->store(new App\Core\Request('POST', '/users', [], [
    'tenant_id' => (string) $tenantB, 'username' => 'forged-super', 'email' => '',
    'role' => 'superadmin', 'password' => 'new-password', 'enabled' => '1',
]));
$check((int) $db->scalar("SELECT COUNT(*) FROM users WHERE username = 'forged-super'") === 0, 'tenant admin cannot create super admin');

$request = new App\Core\Request('POST', '/users/' . $created['id'], [], [
    'tenant_id' => (string) $tenantB, 'email' => 'updated@example.test',
    'role' => 'user', 'enabled' => '1',
]);
$request->params = ['id' => $created['id']];
$controller->update($request);
$updated = $db->one('SELECT * FROM users WHERE id = ?', [$created['id']]);
$check((int) $updated['tenant_id'] === $tenantA && $updated['role'] === 'user' && (int) $updated['active'] === 1,
    'tenant admin may manage safe roles only inside own tenant');

echo "All tenant authentication and role checks passed.\n";
