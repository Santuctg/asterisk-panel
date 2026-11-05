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
    [str_contains($html, '>Registration<') && str_contains($html, 'Unknown'), 'trunk registration status renders when AMI is unavailable'],
    [str_contains($html, '>Uptime<'), 'trunk uptime column renders'],
    [str_contains($html, 'data-modal-open="trunkMonitor1"') && str_contains($html, 'id="trunkMonitor1"'), 'trunk Monitor action opens its popup'],
] as [$ok, $description]) {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    echo 'PASS: ' . $description . PHP_EOL;
}
$_GET['tab'] = 'outbound';
$outboundHtml = (new App\Controllers\RouteController())->index(new App\Core\Request('GET', '/routes'))->body();
if (!str_contains($outboundHtml, '<option value="1">Test trunk</option>')) {
    throw new RuntimeException('FAIL: outbound route selector renders trunk names and IDs');
}
echo "PASS: outbound route selector renders trunk names and IDs\n";
$routeView = file_get_contents(BASE_PATH . '/themes/default/views/routes/index.php');
if (!str_contains($routeView, 'Registration uptime history') || !str_contains($routeView, 'Dial 9 for an outside line')) {
    throw new RuntimeException('FAIL: trunk history and outbound route examples render');
}
echo "PASS: trunk history and outbound route examples render\n";

$extension = $db->insert('extensions', [
    'tenant_id' => $tenant, 'extension' => '201', 'name' => 'Desk 201', 'secret' => 'test-secret', 'enabled' => 1,
]);
$ringGroup = $db->insert('ring_groups', [
    'tenant_id' => $tenant, 'extension' => '600', 'name' => 'Sales', 'enabled' => 1,
]);
$db->insert('ring_group_members', ['ring_group_id' => $ringGroup, 'extension_id' => $extension, 'ordering' => 0]);
$extensionHtml = (new App\Controllers\ExtensionController())->index(new App\Core\Request('GET', '/extensions'))->body();
if (!str_contains($extensionHtml, 'data-modal-open="extensionMonitor' . $extension . '"')
    || !str_contains($extensionHtml, 'id="extensionMonitor' . $extension . '"')) {
    throw new RuntimeException('FAIL: extension Monitor action opens its popup');
}
echo "PASS: extension Monitor action opens its popup\n";
$_GET['tab'] = 'inbound';
$html = (new App\Controllers\RouteController())->index(new App\Core\Request('GET', '/routes'))->body();
foreach ([
    [str_contains($html, 'data-type="extension"') && str_contains($html, '201 — Desk 201'), 'extension destination group renders'],
    [str_contains($html, 'data-type="ringgroup"') && str_contains($html, '600 — Sales'), 'ring group destination group renders'],
    [str_contains($html, 'destination.replaceChildren()'), 'destination selector rebuilds by selected type'],
] as [$ok, $description]) {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    echo 'PASS: ' . $description . PHP_EOL;
}
