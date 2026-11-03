<?php

declare(strict_types=1);

// Run: php tests/trunk-autofill-smoke.php
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) {
    $db->exec($sql);
}
$tenant = $db->insert('tenants', ['name' => 'Test', 'context' => 'test']);
$user = $db->insert('users', [
    'tenant_id' => $tenant, 'username' => 'panel-admin', 'password' => password_hash('test-only', PASSWORD_DEFAULT),
    'role' => 'superadmin', 'active' => 1,
]);
$db->insert('trunks', [
    'tenant_id' => $tenant, 'name' => 'Test trunk', 'host' => 'sip.example.com',
    'username' => 'provider-id', 'secret' => 'trunk-secret-not-for-html', 'enabled' => 1,
]);
$app->auth()->login($user);
$_GET['tab'] = 'trunks';
$html = (new App\Controllers\RouteController())->index(new App\Core\Request('GET', '/routes'))->body();
foreach ([
    [!str_contains($html, 'trunk-secret-not-for-html'), 'trunk secret not rendered in edit HTML'],
    [str_contains($html, 'name="sip_trunk_auth_id"'), 'separate SIP auth ID field'],
    [str_contains($html, 'name="sip_trunk_auth_secret"'), 'separate SIP secret field'],
    [str_contains($html, 'autocomplete="new-password"'), 'prevent login-password autofill'],
] as [$ok, $description]) {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    echo 'PASS: ' . $description . PHP_EOL;
}
