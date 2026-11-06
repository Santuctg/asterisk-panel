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
$secondExtension = $db->insert('extensions', ['tenant_id' => $tid, 'extension' => '2002', 'name' => 'Supervisor', 'secret' => 'test-secret2', 'enabled' => 1]);
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
$announcement = $db->insert('announcements', [
    'tenant_id' => $tid, 'name' => 'Welcome', 'filename' => 'welcome.wav',
    'sound_path' => 'asterisk-panel/tenant_a/welcome', 'enabled' => 1,
]);
$ringGroup = $db->insert('ring_groups', [
    'tenant_id' => $tid, 'extension' => '600', 'name' => 'Sales',
    'timeout' => 25, 'ringback' => 'moh:sales', 'announcement_id' => $announcement, 'enabled' => 1,
]);
$db->insert('ring_group_members', ['ring_group_id' => $ringGroup, 'extension_id' => $targetExtension, 'ordering' => 0]);
$db->insert('ring_group_members', ['ring_group_id' => $ringGroup, 'extension_id' => $secondExtension, 'ordering' => 1]);
$dialplan = $gen->renderDialplan();
$check(str_contains($dialplan, '[panel-listen-only]'), 'browser dialplan cannot call out');
$announcementStep = 'Gosub(panel-announcement,s,1(asterisk-panel/tenant_a/welcome))';
$dialStep = 'Dial(PJSIP/1001@tenant-a.example.test&PJSIP/2002@tenant-a.example.test,25,TtH)';
$check(str_contains($dialplan, '[panel-announcement]')
    && str_contains($dialplan, 'Set(__PANEL_ANNOUNCEMENT_PLAYED=1)')
    && str_contains($dialplan, 'Answer()')
    && str_contains($dialplan, 'Playback(${ARG1})'), 'announcement playback is answered, complete, and guarded against duplicates');
$check(str_contains($dialplan, $announcementStep) && str_contains($dialplan, $dialStep)
    && strpos($dialplan, $announcementStep) < strpos($dialplan, $dialStep)
    && !str_contains($dialplan, 'm(sales)'), 'ring group announcement plays before normal simultaneous ringing');
$liveFeed = (new App\Controllers\DashboardController())->liveCalls(new App\Core\Request('GET', '/dashboard/live-calls'));
$liveData = json_decode($liveFeed->body(), true);
$check($liveFeed->status() === 200 && isset($liveData['online'], $liveData['count'], $liveData['channels'], $liveData['recent_calls']), 'live calls and recent CDR polling feed responds');
$dashboard = (new App\Controllers\DashboardController())->index(new App\Core\Request('GET', '/dashboard'));
$check(str_contains($dashboard->body(), '/dashboard/live-calls') && str_contains($dashboard->body(), 'setTimeout(poll')
    && str_contains($dashboard->body(), 'active-call-grid') && str_contains($dashboard->body(), 'renderRecent'), 'dashboard polls and consistently renders active and recent calls');
$check(str_contains($dashboard->body(), 'method="post" action="/system/apply"')
    && !str_contains($dashboard->body(), 'href="/system/apply"'), 'dashboard applies Asterisk config with a protected POST form');
$check(App\Controllers\DashboardController::activeCallCount([
    ['name' => 'PJSIP/1001-a', 'bridged' => 'PJSIP/2002-b'],
    ['name' => 'PJSIP/2002-b', 'bridged' => 'PJSIP/1001-a'],
    ['name' => 'PJSIP/1001-c', 'bridged' => ''],
]) === 2, 'bridged channel legs render as one active call card');
$updateProgress = (new App\Controllers\SystemController())->updateProgress(new App\Core\Request('GET', '/system/update/progress'));
$check($updateProgress->status() === 200 && isset(json_decode($updateProgress->body(), true)['state']), 'system update progress endpoint remains available during background updates');
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
$trackedTrunk = $db->insert('trunks', [
    'tenant_id' => $tid, 'name' => 'Tracked', 'host' => 'sip.example.test',
    'username' => 'provider-user', 'secret' => 'provider-secret', 'enabled' => 1,
]);
$trunkConfig = $gen->renderPjsipTrunks();
$check(str_contains($trunkConfig, "[trunk-{$trackedTrunk}-identify]\ntype = identify\nendpoint = trunk-{$trackedTrunk}\nmatch = sip.example.test"), 'provider host identifies inbound trunk calls');
$settingsFiles = $gen->generateFor('settings');
$check(in_array('pjsip_port.conf', $settingsFiles, true) && in_array('dialplan.conf', $settingsFiles, true), 'system settings regenerate SIP and tenant dialplan configuration');
$tracker = new App\Services\Asterisk\TrunkStatusTracker();
$tracker->recordStatuses(['trunk-' . $trackedTrunk => 'Registered']);
$tracker->recordStatuses(['trunk-' . $trackedTrunk => 'Registered']);
$check((int) $db->scalar('SELECT COUNT(*) FROM trunk_status_history WHERE trunk_id = ?', [$trackedTrunk]) === 1, 'unchanged trunk state extends one uptime interval');
$tracker->recordStatuses(['trunk-' . $trackedTrunk => 'Rejected']);
$check((int) $db->scalar('SELECT COUNT(*) FROM trunk_status_history WHERE trunk_id = ?', [$trackedTrunk]) === 2
    && (int) $db->scalar('SELECT COUNT(*) FROM trunk_status_history WHERE trunk_id = ? AND ended_at IS NULL', [$trackedTrunk]) === 1, 'trunk state changes create persistent history');
$outboundTime = $db->insert('time_conditions', [
    'tenant_id' => $tid, 'name' => 'Business hours', 'days_of_week' => 'mon-fri',
    'day_of_month' => '*', 'month' => '*', 'start_hour' => 9, 'start_minute' => 0,
    'end_hour' => 17, 'end_minute' => 0, 'open_type' => 'ringgroup', 'open_dest_id' => $ringGroup,
    'closed_type' => 'hangup', 'enabled' => 1,
]);
$db->insert('inbound_routes', [
    'tenant_id' => $tid, 'name' => 'Scheduled inbound', 'did_pattern' => '15551230000',
    'dest_type' => 'timecond', 'dest_id' => $outboundTime, 'enabled' => 1,
]);
$outboundRoute = $db->insert('outbound_routes', [
    'tenant_id' => $tid, 'name' => 'Outside via 9', 'prefix' => '9', 'match_pattern' => '_X.',
    'trunk_id' => $trackedTrunk, 'time_condition_id' => $outboundTime, 'priority' => 0, 'enabled' => 1,
]);
$db->insert('outbound_route_schedules', [
    'tenant_id' => $tid, 'outbound_route_id' => $outboundRoute, 'name' => 'Office hours',
    'days_of_week' => 'sun&mon&wed&sat', 'start_time' => '09:00', 'end_time' => '17:00', 'ordering' => 0,
]);
$db->update('extensions', $targetExtension, ['outbound_route_id' => $outboundRoute]);
$outboundDialplan = $gen->renderDialplan();
$outboundPjsip = $gen->renderPjsipEndpoints();
$check(str_contains($outboundDialplan, '[tenant_a-outbound]')
    && str_contains($outboundDialplan, '${PANEL_OUTBOUND_ROUTE_ID}"="' . $outboundRoute)
    && str_contains($outboundDialplan, '[tenant_a-outbound-' . $outboundRoute . ']')
    && str_contains($outboundDialplan, 'GotoIfTime(09:00-17:00,sun&mon&wed&sat,*,*,America/New_York?time-allowed)')
    && str_contains($outboundDialplan, 'Outbound calling schedule matched')
    && str_contains($outboundDialplan, '[tenant_a-timecond-' . $outboundTime . ']')
    && str_contains($outboundDialplan, 'Gosub(tenant_a-timecond-' . $outboundTime . ',s,1 )')
    && str_contains($outboundDialplan, 'exten => _9X.,1,NoOp(Outbound: Outside via 9)')
    && str_contains($outboundDialplan, 'Dial(PJSIP/${EXTEN:1}@trunk-' . $trackedTrunk . ',30,Tt)')
    && str_contains($outboundPjsip, 'set_var = PANEL_OUTBOUND_ROUTE_ID=' . $outboundRoute), 'assigned outbound route, schedule, and prefix stripping are enforced');
$check(substr_count($outboundPjsip, 'set_var = PANEL_OUTBOUND_ROUTE_ID=' . $outboundRoute) === 2,
    'outbound permission applies to the desk phone and calling web endpoint only');

$registeredForA = App\Controllers\DashboardController::registeredPhoneCount(
    [['extension' => '1001'], ['extension' => '2002']],
    [
        '1001@tenant-a.example.test' => 'Avail',
        'tenant_a_2002-phone-web' => 'Avail',
        '1001@tenant-b.example.test' => 'Avail',
        'trunk-1-aor' => 'Avail',
    ],
    'tenant-a.example.test',
    'tenant_a'
);
$check($registeredForA === 2, 'dashboard registered-phone count excludes trunks and other tenants');

// Extension registration history works the same way as trunk history.
$extTracker = new App\Services\Asterisk\ExtensionStatusTracker();
$extTracker->recordStatuses(['ext-' . $targetExtension => 'Registered']);
$extTracker->recordStatuses(['ext-' . $targetExtension => 'Registered']);
$check((int) $db->scalar('SELECT COUNT(*) FROM extension_status_history WHERE extension_id = ?', [$targetExtension]) === 1, 'unchanged extension state extends one uptime interval');
$extTracker->recordStatuses(['ext-' . $targetExtension => 'Unregistered']);
$check((int) $db->scalar('SELECT COUNT(*) FROM extension_status_history WHERE extension_id = ?', [$targetExtension]) === 2
    && (int) $db->scalar('SELECT COUNT(*) FROM extension_status_history WHERE extension_id = ? AND ended_at IS NULL', [$targetExtension]) === 1, 'extension state changes create persistent history');

// Latency (RTT) and active-device parsing for the monitoring columns.
$contactDetails = App\Services\Asterisk\AsteriskManager::parseContactDetails(
    "Contact: 1001@tenant-a.example.test/sip:1001@192.0.2.10 abc123 Avail 12.000\n" .
    "Contact: 1001@tenant-a.example.test/sip:1001@192.0.2.11 def456 Avail 14.000\n" .
    "Contact: trunk-1-aor/sip:198.51.100.10 ghi789 NonQual nan\n"
);
$check(($contactDetails['1001@tenant-a.example.test']['count'] ?? 0) === 2
    && ($contactDetails['1001@tenant-a.example.test']['rtt'] ?? null) === 14.0
    && array_key_exists('rtt', $contactDetails['trunk-1-aor'] ?? [])
    && $contactDetails['trunk-1-aor']['rtt'] === null, 'contact latency and device-count parsing');

// 24-hour availability timeline stays inside its window and classifies states.
$timelineSegments = status_timeline([
    ['status' => 'Registered', 'started_at' => date('Y-m-d H:i:s', time() - 3600), 'ended_at' => null],
    ['status' => 'Unregistered', 'started_at' => date('Y-m-d H:i:s', time() - 7200), 'ended_at' => date('Y-m-d H:i:s', time() - 7000)],
], time(), 24);
$check(count($timelineSegments) === 2
    && ($timelineSegments[0]['left'] + $timelineSegments[0]['width']) <= 100.001
    && ($timelineSegments[1]['left'] + $timelineSegments[1]['width']) <= 100.001
    && status_timeline_class('Registered') === 'ok'
    && status_timeline_class('Unregistered') === 'down'
    && status_timeline_class('Disabled') === 'off', 'availability timeline segments are clamped and classified');

$snapshot = App\Services\Asterisk\AsteriskManager::parseConciseChannels(
    'PJSIP/1001@tenant-a.example.test-00000001!tenant_a!1001!1!Up!Dial!PJSIP/trunk!Caller!acct!0!00:20!PJSIP/trunk-00000002!uid' . "\n" .
    'PJSIP/2002@tenant-a.example.test-00000003!tenant_a!2002!1!Ringing!Dial!x!Caller!acct!0!00:02!!uid' . "\n" .
    'PJSIP/trunk-1-00000004!tenant_a-inbound!5551234!1!Ring!Dial!PJSIP/1001!External!acct!0!00:01!PJSIP/1001@tenant-a.example.test-00000001!uid' . "\n" .
    'not a channel'
);
$tenantSnapshot = App\Services\Asterisk\AsteriskManager::filterTenantChannels(
    $snapshot,
    [['extension' => '1001'], ['extension' => '2002']],
    'tenant-a.example.test',
    'tenant_a'
);
$check(count($snapshot) === 3
    && $snapshot['PJSIP/1001@tenant-a.example.test-00000001']['bridged'] === 'PJSIP/trunk-00000002'
    && $snapshot['PJSIP/1001@tenant-a.example.test-00000001']['app'] === 'Dial'
    && count($tenantSnapshot) === 3, 'concise live channels include tenant trunk and extension legs');

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
