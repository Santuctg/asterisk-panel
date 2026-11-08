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
$tenantNoDomain2 = $db->insert('tenants', ['name' => 'Legacy 2', 'context' => 'legacy2', 'domain' => null, 'enabled' => 1]);
$password = password_hash('correct-horse', PASSWORD_DEFAULT);
$super = $db->insert('users', ['tenant_id' => null, 'username' => 'rootadmin', 'password' => $password, 'role' => 'superadmin', 'active' => 1]);
$adminA = $db->insert('users', ['tenant_id' => $tenantA, 'username' => 'admin-a', 'password' => $password, 'role' => 'admin', 'active' => 1]);
$adminB = $db->insert('users', ['tenant_id' => $tenantB, 'username' => 'admin-b', 'password' => $password, 'role' => 'admin', 'active' => 1]);
$legacy = $db->insert('users', ['tenant_id' => $tenantNoDomain, 'username' => 'legacy-user', 'password' => $password, 'role' => 'user', 'active' => 1]);
$db->insert('users', ['tenant_id' => $tenantNoDomain, 'username' => 'shared-main', 'password' => $password, 'role' => 'user', 'active' => 1]);
$db->insert('users', ['tenant_id' => $tenantNoDomain2, 'username' => 'shared-main', 'password' => $password, 'role' => 'user', 'active' => 1]);

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
$check($allows('panel.example.test', $adminA), 'tenant user may use the primary panel domain');
$check($allows('a.example.test', $super), 'super admin may use tenant domain');
$check($allows('panel.example.test', $super), 'super admin may use main domain');
$check($allows('panel.example.test', $legacy), 'domain-less tenant user may use main domain');
$check(!$allows('unknown.example.test', $super), 'unknown host fails closed');

$_SERVER['HTTP_HOST'] = 'a.example.test';
$check((new App\Core\Auth())->attempt('admin-a', 'correct-horse'), 'matching tenant login succeeds');
$app->auth()->logout();
$check((new App\Core\Auth())->attempt('rootadmin', 'correct-horse'), 'super admin login succeeds on tenant domain');
$_SERVER['HTTP_HOST'] = 'b.example.test';
$check(!(new App\Core\Auth())->attempt('admin-a', 'correct-horse'), 'cross-tenant login is rejected');
$_SERVER['HTTP_HOST'] = 'panel.example.test';
$check((new App\Core\Auth())->attempt('admin-a', 'correct-horse'), 'tenant login on main host succeeds');
$app->auth()->logout();
$db->insert('tenant_login_bans', ['tenant_id' => $tenantA, 'ip' => '203.0.113.19', 'reason' => 'main-host test']);
$_SERVER['REMOTE_ADDR'] = '203.0.113.19';
$check(!(new App\Core\Auth())->attempt('admin-a', 'correct-horse'), 'tenant IP block also applies on the primary panel domain');
$db->run('DELETE FROM tenant_login_bans WHERE tenant_id = ? AND ip = ?', [$tenantA, '203.0.113.19']);
unset($_SERVER['REMOTE_ADDR']);
$check(!(new App\Core\Auth())->attempt('shared-main', 'correct-horse'), 'ambiguous shared-host credentials fail closed');

// Role assignment is server-side scoped even when a tenant admin crafts POST data.
$_SERVER['HTTP_HOST'] = 'a.example.test';
$app->auth()->login($adminA);
$controller = new App\Controllers\UserController();
$page = $controller->index(new App\Core\Request('GET', '/users'));
$check(str_contains($page->body(), 'admin-a') && !str_contains($page->body(), 'admin-b'), 'user list is scoped to own tenant');
$check(!str_contains($page->body(), $password), 'user password hashes are never rendered');
$_SERVER['HTTP_HOST'] = 'panel.example.test';
$_SESSION['active_tenant'] = $tenantA;
$app->auth()->login($super);
$superPage = $controller->index(new App\Core\Request('GET', '/users'));
$check(str_contains($superPage->body(), 'admin-a') && substr_count($superPage->body(), 'rootadmin') === 1
    && !str_contains($superPage->body(), 'admin-b') && !str_contains($superPage->body(), 'value="superadmin"'),
    'superadmin user list contains only active tenant users');
$dashboard = new App\Controllers\DashboardController();
$switchResponse = $dashboard->switchTenant(new App\Core\Request('POST', '/dashboard/switch-tenant', [], [
    'tenant_id' => (string) $tenantB,
    'return_to' => '/extensions?status=enabled&page=2',
]));
$check((int) ($_SESSION['active_tenant'] ?? 0) === $tenantB
    && ($switchResponse->headers()['Location'] ?? '') === '/extensions?status=enabled&page=2',
    'tenant switch preserves the current page and query string');
$unsafeSwitch = $dashboard->switchTenant(new App\Core\Request('POST', '/dashboard/switch-tenant', [], [
    'tenant_id' => (string) $tenantA,
    'return_to' => '//evil.example/steal',
]));
$check(($unsafeSwitch->headers()['Location'] ?? '') === '/dashboard',
    'tenant switch rejects external redirect destinations');
$_SERVER['HTTP_HOST'] = 'a.example.test';
$app->auth()->login($adminA);
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

$controller->store(new App\Core\Request('POST', '/users', [], [
    'username' => 'same-user', 'email' => '', 'role' => 'user', 'password' => 'new-password', 'enabled' => '1',
]));
$_SERVER['HTTP_HOST'] = 'b.example.test';
$app->auth()->login($adminB);
$controller->store(new App\Core\Request('POST', '/users', [], [
    'username' => 'same-user', 'email' => '', 'role' => 'user', 'password' => 'new-password', 'enabled' => '1',
]));
$check((int) $db->scalar("SELECT COUNT(*) FROM users WHERE username = 'same-user'") === 2, 'same username may exist in different tenants');
// Exercise the real controller: the generic unique validator must receive
// tenant_id before checking a number already used by another tenant.
$extensions = new App\Controllers\ExtensionController();
$extensions->store(new App\Core\Request('POST', '/extensions', [], [
    'extension'=>'1001','name'=>'B 1001','secret'=>'1','enabled'=>'1',
]));
$_SERVER['HTTP_HOST'] = 'a.example.test';
$app->auth()->login($adminA);
$extensions->store(new App\Core\Request('POST', '/extensions', [], [
    'extension'=>'1001','name'=>'A 1001','secret'=>'1','enabled'=>'1',
]));
$check((int) $db->scalar("SELECT COUNT(*) FROM extensions WHERE extension = '1001'") === 2,
    'same extension may exist in different tenants');

$request = new App\Core\Request('POST', '/users/' . $created['id'], [], [
    'tenant_id' => (string) $tenantB, 'email' => 'updated@example.test',
    'role' => 'user', 'enabled' => '1',
]);
$request->params = ['id' => $created['id']];
$controller->update($request);
$updated = $db->one('SELECT * FROM users WHERE id = ?', [$created['id']]);
$check((int) $updated['tenant_id'] === $tenantA && $updated['role'] === 'user' && (int) $updated['active'] === 1,
    'tenant admin may manage safe roles only inside own tenant');

$roles = new App\Controllers\RoleController();
$roles->store(new App\Core\Request('POST', '/roles', [], [
    'name' => 'Call auditor', 'permissions' => ['cdr.view', 'cdr.delete', 'user.manage', 'role.manage', 'tenant.manage'],
]));
$custom = $db->one("SELECT * FROM roles WHERE tenant_id = ? AND slug = 'call-auditor'", [$tenantA]);
$check($custom !== null, 'tenant admin may create a tenant-scoped role');
$roles->store(new App\Core\Request('POST', '/roles', [], [
    'name' => 'Power operators', 'permissions' => ['extension.manage', 'user.manage'],
]));
$powerUser = $db->insert('users', [
    'tenant_id' => $tenantA, 'username' => 'power-user', 'password' => $password,
    'role' => 'power-operators', 'active' => 1,
]);
$rolePage = $roles->index(new App\Core\Request('GET', '/roles'));
$check($rolePage->status() === 200 && str_contains($rolePage->body(), 'Call auditor'), 'custom role management page renders');
$customUser = $db->insert('users', [
    'tenant_id' => $tenantA, 'username' => 'auditor', 'password' => $password,
    'role' => 'call-auditor', 'active' => 1,
]);
$app->auth()->login($customUser);
$check($app->auth()->can('cdr.view') && $app->auth()->can('cdr.delete')
    && $app->auth()->can('user.manage') && $app->auth()->can('role.manage') && !$app->auth()->can('tenant.manage'),
    'custom role grants only selected safe permissions');
$customUsers = new App\Controllers\UserController();
$customUsers->store(new App\Core\Request('POST', '/users', [], [
    'username'=>'forged-admin','role'=>'admin','password'=>'new-password','enabled'=>'1',
]));
$check((int)$db->scalar("SELECT COUNT(*) FROM users WHERE username='forged-admin'") === 0,
    'custom user manager cannot create tenant admin');
$roles->store(new App\Core\Request('POST', '/roles', [], [
    'name' => 'Escalated role', 'permissions' => ['extension.manage', 'role.manage'],
]));
$check((int) $db->scalar("SELECT COUNT(*) FROM roles WHERE tenant_id = ? AND slug = 'escalated-role'", [$tenantA]) === 0,
    'delegated role manager cannot grant permissions they do not have');
$customUsers->store(new App\Core\Request('POST', '/users', [], [
    'username' => 'forged-power', 'role' => 'power-operators', 'password' => 'new-password', 'enabled' => '1',
]));
$check((int) $db->scalar("SELECT COUNT(*) FROM users WHERE username = 'forged-power'") === 0,
    'delegated user manager cannot assign a more privileged custom role');
$powerUpdate = new App\Core\Request('POST', '/users/' . $powerUser, [], [
    'email' => 'taken-over@example.test', 'role' => 'power-operators',
    'password' => 'takeover-password', 'enabled' => '1',
]);
$powerUpdate->params = ['id' => $powerUser];
$customUsers->update($powerUpdate);
$powerRow = $db->one('SELECT email, password FROM users WHERE id = ?', [$powerUser]);
$check(($powerRow['email'] ?? '') !== 'taken-over@example.test'
    && !password_verify('takeover-password', (string) ($powerRow['password'] ?? '')),
    'delegated user manager cannot reset a more privileged custom user password');

$_SERVER['HTTP_HOST'] = 'a.example.test';
$security = new App\Controllers\TenantSecurityController();
$security->store(new App\Core\Request('POST', '/tenant-security', [], ['ip'=>'203.0.113.77','reason'=>'test block']));
$check((int)$db->scalar('SELECT COUNT(*) FROM tenant_login_bans WHERE tenant_id = ? AND ip = ?', [$tenantA,'203.0.113.77']) === 1,
    'tenant admin can block an IP for own tenant');
$check((new App\Services\TenantSecurity())->isBlocked($tenantA, '203.0.113.77')
    && !(new App\Services\TenantSecurity())->isBlocked($tenantB, '203.0.113.77'),
    'tenant login block is isolated per tenant');

// Main-panel throttling must survive a replaced session cookie and must not
// share reset state with successful Web Phone authentication from the same IP.
$app->auth()->logout();
$_SERVER['HTTP_HOST'] = 'a.example.test';
$loginIp = '192.0.2.88';
$authController = new App\Controllers\AuthController();
for ($attempt = 1; $attempt <= 5; $attempt++) {
    $_SESSION = []; // Simulate replacing the panel session cookie each time.
    $failedPanelLogin = $authController->login(new App\Core\Request(
        'POST', '/login', [], ['username' => 'admin-a', 'password' => 'wrong-password'],
        ['REMOTE_ADDR' => $loginIp, 'HTTP_HOST' => 'a.example.test']
    ));
}
$_SESSION = [];
$blockedPanelLogin = $authController->login(new App\Core\Request(
    'POST', '/login', [], ['username' => 'admin-a', 'password' => 'wrong-password'],
    ['REMOTE_ADDR' => $loginIp, 'HTTP_HOST' => 'a.example.test']
));
$panelKey = (new App\Services\WebPhoneLoginThrottle())->key(
    $loginIp,
    App\Services\WebPhoneLoginThrottle::PANEL
);
$check($failedPanelLogin->status() === 302 && $blockedPanelLogin->status() === 429
    && (int) ($blockedPanelLogin->headers()['Retry-After'] ?? 0) > 0
    && (int) $db->scalar('SELECT attempts FROM webphone_login_throttles WHERE key_hash = ?', [$panelKey]) === 5,
    'panel login throttling survives session-cookie replacement and returns Retry-After');
$webPhoneLogin = (new App\Controllers\WebPhoneController())->connect(new App\Core\Request(
    'POST', '/web-phone/connect', [], ['extension' => '1001', 'secret' => '1'],
    ['REMOTE_ADDR' => $loginIp, 'HTTP_HOST' => 'a.example.test']
));
$stillBlocked = $authController->login(new App\Core\Request(
    'POST', '/login', [], ['username' => 'admin-a', 'password' => 'correct-horse'],
    ['REMOTE_ADDR' => $loginIp, 'HTTP_HOST' => 'a.example.test']
));
$check($webPhoneLogin->status() === 200 && $stillBlocked->status() === 429,
    'successful Web Phone authentication cannot reset panel-login failures');

echo "All tenant authentication and role checks passed.\n";
