<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('APP_PRIMARY_HOST=panel.example.test');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) { $db->exec($sql); }

$tenant = $db->insert('tenants', ['name' => 'Test', 'context' => 'test', 'domain' => 'test.example.test', 'enabled' => 1]);
$user = $db->insert('users', [
    'tenant_id' => $tenant, 'username' => 'admin', 'password' => password_hash('test-password', PASSWORD_DEFAULT),
    'role' => 'admin', 'active' => 1,
]);
$db->insert('extensions', [
    'tenant_id' => $tenant, 'extension' => '201', 'name' => 'Desk 201', 'secret' => 'test-secret', 'enabled' => 1,
]);
$_SERVER['HTTP_HOST'] = 'test.example.test';
$app->auth()->login($user);

$controller = new App\Controllers\IvrController();
$page = $controller->create(new App\Core\Request('GET', '/ivr/create'));
if ($page->status() !== 200 || !str_contains($page->body(), 'New IVR menu')) {
    throw new RuntimeException('IVR create page did not render');
}
if (!str_contains($page->body(), '201 — Desk 201')) {
    throw new RuntimeException('IVR destination labels did not render');
}

$response = $controller->store(new App\Core\Request('POST', '/ivr', [], [
    'extension' => '700', 'name' => 'Main Menu', 'greeting' => '',
    'timeout' => '3', 'response_timeout' => '8', 'max_retries' => '3',
    'key' => ['1'], 'key_type' => ['extension'], 'key_dest' => ['1'],
    'timeout_action' => '', 'timeout_dest_id' => '', 'enabled' => '1',
]));
if ($response->status() !== 302 || (int) $db->scalar('SELECT COUNT(*) FROM ivr_menus WHERE tenant_id = ?', [$tenant]) !== 1) {
    throw new RuntimeException('IVR menu was not created');
}

$dialplan = (new App\Services\Asterisk\ConfigGenerator())->renderDialplan();
$ivrContext = '[test-ivr-700]';
$ivrStart = strpos($dialplan, $ivrContext);
if ($ivrStart === false) {
    throw new RuntimeException('IVR helper context was not generated');
}
$ivrDialplan = substr($dialplan, $ivrStart, 1200);
if (!str_contains($dialplan, 'Goto(test-ivr-700,s,1)')
    || !str_contains($ivrDialplan, 'same => n(menu),Set(__PANEL_CALL_STAGE=IVR: Main Menu)')
    || !str_contains($ivrDialplan, 'same => n,Background(silence/1)')
    || !str_contains($ivrDialplan, 'same => n,WaitExten()')
    || !str_contains($ivrDialplan, 'exten => 1,1,Gosub(test,201,1)')
    || !str_contains($ivrDialplan, 'same => n,Set(IVR_RETRIES=$[${IVR_RETRIES} + 1])')
    || !str_contains($ivrDialplan, 'same => n,GotoIf($[${IVR_RETRIES} >= 3]?exhausted:retry)')) {
    throw new RuntimeException('IVR menu routing, waiting, or retry handling was not generated correctly');
}

echo "All IVR create checks passed.\n";
