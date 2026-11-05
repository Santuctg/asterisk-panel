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
$tid = $db->insert('tenants', ['name' => 'Tenant A', 'context' => 'tenant_a', 'domain' => 'tenant-a.example.test', 'enabled' => 1]);
$other = $db->insert('tenants', ['name' => 'Tenant B', 'context' => 'tenant_b', 'domain' => 'tenant-b.example.test', 'enabled' => 1]);
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
$check(str_contains($pjsip, "[1001@tenant-a.example.test](endpoint_basic)\ncontext = tenant_a\nauth = 1001@tenant-a.example.test\naors = 1001@tenant-a.example.test\nidentify_by = username,auth_username")
    && str_contains($pjsip, "[1001@tenant-a.example.test]\ntype = auth\nauth_type = userpass\nusername = 1001\npassword = test-secret\nrealm = tenant-a.example.test")
    && str_contains($pjsip, '[1001@tenant-b.example.test](endpoint_basic)'), 'physical SIP usernames are isolated by tenant domain');
$check(str_contains($pjsip, '[tenant_a_2002-web]') && str_contains($pjsip, 'context = panel-listen-only'), 'separate browser endpoints are restricted');
$device = ['mac' => '001565aabbcc', 'vendor' => 'generic', 'model' => '', 'extension_id' => $targetExtension, 'description' => 'Desk'];
$provisioned = (new App\Services\ProvisioningService())->render($device)['body'];
$check(str_contains($provisioned, 'sip_server=tenant-a.example.test')
    && str_contains($provisioned, 'username=1001')
    && str_contains($provisioned, 'auth_name=1001')
    && str_contains($provisioned, 'domain=tenant-a.example.test'), 'device provisioning uses username plus tenant realm');
$ringGroup = $db->insert('ring_groups', [
    'tenant_id' => $tid, 'extension' => '600', 'name' => 'Sales',
    'timeout' => 25, 'ringback' => 'moh:sales', 'enabled' => 1,
]);
$db->insert('ring_group_members', ['ring_group_id' => $ringGroup, 'extension_id' => $targetExtension, 'ordering' => 0]);
$dialplan = $gen->renderDialplan();
$check(str_contains($dialplan, '[panel-listen-only]'), 'browser dialplan cannot call out');
$check(str_contains($dialplan, 'Dial(PJSIP/1001@tenant-a.example.test,25,TtHm(sales))'), 'ring group call tune uses selected MOH class');
$liveFeed = (new App\Controllers\DashboardController())->liveCalls(new App\Core\Request('GET', '/dashboard/live-calls'));
$liveData = json_decode($liveFeed->body(), true);
$check($liveFeed->status() === 200 && isset($liveData['online'], $liveData['count'], $liveData['channels']), 'live calls polling feed responds');
$dashboard = (new App\Controllers\DashboardController())->index(new App\Core\Request('GET', '/dashboard'));
$check(str_contains($dashboard->body(), '/dashboard/live-calls') && str_contains($dashboard->body(), 'setTimeout(poll'), 'dashboard polls live calls automatically');
$contactStatuses = App\Services\Asterisk\AsteriskManager::parseContactStatuses(
    "Contact: 201@pbx.example.test/sip:201@192.0.2.10 abc123 Avail 12.000\n" .
    "Contact: trunk-1-aor/sip:198.51.100.10 def456 NonQual nan\n"
);
$check(($contactStatuses['201@pbx.example.test'] ?? '') === 'Avail' && ($contactStatuses['trunk-1-aor'] ?? '') === 'NonQual', 'SIP contact registration parsing');
$registrations = App\Services\Asterisk\AsteriskManager::parseOutboundRegistrations(
    " trunk-1/sip:198.51.100.10 trunk-1-auth Registered (exp. 20s)\n" .
    " trunk-2/sip:203.0.113.10 trunk-2-auth Rejected\n"
);
$check(($registrations['trunk-1'] ?? '') === 'Registered' && ($registrations['trunk-2'] ?? '') === 'Rejected', 'outbound trunk registration parsing');
$trackedTrunk = $db->insert('trunks', ['tenant_id' => $tid, 'name' => 'Tracked', 'host' => 'sip.example.test', 'enabled' => 1]);
$tracker = new App\Services\Asterisk\TrunkStatusTracker();
$tracker->recordStatuses(['trunk-' . $trackedTrunk => 'Registered']);
$tracker->recordStatuses(['trunk-' . $trackedTrunk => 'Registered']);
$check((int) $db->scalar('SELECT COUNT(*) FROM trunk_status_history WHERE trunk_id = ?', [$trackedTrunk]) === 1, 'unchanged trunk state extends one uptime interval');
$tracker->recordStatuses(['trunk-' . $trackedTrunk => 'Rejected']);
$check((int) $db->scalar('SELECT COUNT(*) FROM trunk_status_history WHERE trunk_id = ?', [$trackedTrunk]) === 2
    && (int) $db->scalar('SELECT COUNT(*) FROM trunk_status_history WHERE trunk_id = ? AND ended_at IS NULL', [$trackedTrunk]) === 1, 'trunk state changes create persistent history');
$db->insert('outbound_routes', [
    'tenant_id' => $tid, 'name' => 'Outside via 9', 'prefix' => '9', 'match_pattern' => '_X.',
    'trunk_id' => $trackedTrunk, 'priority' => 0, 'enabled' => 1,
]);
$outboundDialplan = $gen->renderDialplan();
$check(str_contains($outboundDialplan, 'exten => _9X.,1,NoOp(Outbound: Outside via 9)')
    && str_contains($outboundDialplan, 'Dial(PJSIP/trunk-' . $trackedTrunk . '/${EXTEN:1},30,Tt)'), 'outbound dial prefix is matched and stripped');

$snapshot = App\Services\Asterisk\AsteriskManager::parseConciseChannels(
    'PJSIP/1001@tenant-a.example.test-00000001!tenant_a!1001!1!Up!Dial!PJSIP/trunk!Caller!acct!0!00:20!PJSIP/trunk-00000002!uid' . "\n" .
    'PJSIP/2002@tenant-a.example.test-00000003!tenant_a!2002!1!Ringing!Dial!x!Caller!acct!0!00:02!!uid' . "\n" .
    'not a channel'
);
$check(count($snapshot) === 2 && $snapshot['PJSIP/1001@tenant-a.example.test-00000001']['bridged'] === 'PJSIP/trunk-00000002', 'concise channel bridge parsing');

$controller = new App\Controllers\LiveListenController();
$page = $controller->index(new App\Core\Request('GET', '/live-listen'));
$check($page->status() === 200 && str_contains($page->body(), 'Live Listen')
    && str_contains($page->body(), 'Supervisor') && !str_contains($page->body(), 'Other'), 'console renders scoped extensions');
$request = static fn (string $channel, string $supervisor): App\Core\Request => new App\Core\Request(
    'POST', '/live-listen/start', [], ['channel' => $channel, 'supervisor' => $supervisor, 'mode' => 'phone']
);
$check($controller->start($request('PJSIP/1001@tenant-b.example.test-00000001', '2002'))->status() === 403, 'cannot listen to another tenant');
$check($controller->start($request('PJSIP/1001@tenant-a.example.test-00000001', '1001'))->status() === 403, 'cannot listen on the target extension itself');
$check($controller->start($request('PJSIP/1001@tenant-a.example.test-00000001', '2002'))->status() === 409, 'stale/offline target cannot be originated');
$db->insert('tenants', ['name' => 'Ambiguous', 'context' => 'tenant-a', 'enabled' => 1]);
$check($controller->start($request('PJSIP/1001@tenant-a.example.test-00000001', '2002'))->status() === 409, 'ambiguous flattened contexts fail closed');
$db->update('users', $user, ['role' => 'user']);
$app->auth()->login($user);
$check(!$app->auth()->can('call.listen'), 'ordinary panel user cannot listen');
echo "All live-listen smoke checks passed.\n";
