<?php

declare(strict_types=1);

// Run: php tests/live-listen-smoke.php (uses only an in-memory SQLite DB).
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('AMI_HOST=127.0.0.1');
putenv('AMI_PORT=1');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) {
    $db->exec($sql);
}
$tid = $db->insert('tenants', ['name' => 'Tenant A', 'context' => 'tenant_a', 'enabled' => 1]);
$other = $db->insert('tenants', ['name' => 'Tenant B', 'context' => 'tenant_b', 'enabled' => 1]);
$user = $db->insert('users', [
    'tenant_id' => $tid, 'username' => 'adminA', 'password' => password_hash('test-pass', PASSWORD_DEFAULT),
    'role' => 'admin', 'active' => 1,
]);
$targetExtension = $db->insert('extensions', ['tenant_id' => $tid, 'extension' => '1001', 'name' => 'Target', 'secret' => 'test-secret', 'enabled' => 1]);
$db->insert('extensions', ['tenant_id' => $tid, 'extension' => '2002', 'name' => 'Supervisor', 'secret' => 'test-secret2', 'enabled' => 1]);
$db->insert('extensions', ['tenant_id' => $other, 'extension' => '1001', 'name' => 'Other', 'secret' => 'test-secret3', 'enabled' => 1]);
$app->auth()->login($user);
$_SESSION['active_tenant'] = $other; // stale/malicious tenant switch must not work

$check = static function (bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo 'PASS: ' . $label . PHP_EOL;
};
$check(tenant_id() === $tid, 'tenant scope cannot be overridden by session');
$check($app->auth()->can('call.listen'), 'tenant admin may listen');
$gen = new App\Services\Asterisk\ConfigGenerator();
$pjsip = $gen->renderPjsipEndpoints();
$check(str_contains($pjsip, '[tenant_a_2002-web]') && str_contains($pjsip, 'context = panel-listen-only'), 'separate browser endpoints are restricted');
$ringGroup = $db->insert('ring_groups', [
    'tenant_id' => $tid, 'extension' => '600', 'name' => 'Sales',
    'timeout' => 25, 'ringback' => 'moh:sales', 'enabled' => 1,
]);
$db->insert('ring_group_members', ['ring_group_id' => $ringGroup, 'extension_id' => $targetExtension, 'ordering' => 0]);
$dialplan = $gen->renderDialplan();
$check(str_contains($dialplan, '[panel-listen-only]'), 'browser dialplan cannot call out');
$check(str_contains($dialplan, 'Dial(PJSIP/tenant_a_1001,25,TtHm(sales))'), 'ring group call tune uses selected MOH class');

$snapshot = App\Services\Asterisk\AsteriskManager::parseConciseChannels(
    'PJSIP/tenant_a_1001-00000001!tenant_a!1001!1!Up!Dial!PJSIP/trunk!Caller!acct!0!00:20!PJSIP/trunk-00000002!uid' . "\n" .
    'PJSIP/tenant_a_2002-00000003!tenant_a!2002!1!Ringing!Dial!x!Caller!acct!0!00:02!!uid' . "\n" .
    'not a channel'
);
$check(count($snapshot) === 2 && $snapshot['PJSIP/tenant_a_1001-00000001']['bridged'] === 'PJSIP/trunk-00000002', 'concise channel bridge parsing');

$controller = new App\Controllers\LiveListenController();
$page = $controller->index(new App\Core\Request('GET', '/live-listen'));
$check($page->status() === 200 && str_contains($page->body(), 'Live Listen')
    && str_contains($page->body(), 'Supervisor') && !str_contains($page->body(), 'Other'), 'console renders scoped extensions');
$request = static fn (string $channel, string $supervisor): App\Core\Request => new App\Core\Request(
    'POST', '/live-listen/start', [], ['channel' => $channel, 'supervisor' => $supervisor, 'mode' => 'phone']
);
$check($controller->start($request('PJSIP/tenant_b_1001-00000001', '2002'))->status() === 403, 'cannot listen to another tenant');
$check($controller->start($request('PJSIP/tenant_a_1001-00000001', '1001'))->status() === 403, 'cannot listen on the target extension itself');
$check($controller->start($request('PJSIP/tenant_a_1001-00000001', '2002'))->status() === 409, 'stale/offline target cannot be originated');
$db->insert('tenants', ['name' => 'Ambiguous', 'context' => 'tenant-a', 'enabled' => 1]);
$check($controller->start($request('PJSIP/tenant_a_1001-00000001', '2002'))->status() === 409, 'ambiguous flattened contexts fail closed');
$db->update('users', $user, ['role' => 'user']);
$app->auth()->login($user);
$check(!$app->auth()->can('call.listen'), 'ordinary panel user cannot listen');
echo "All live-listen smoke checks passed.\n";
