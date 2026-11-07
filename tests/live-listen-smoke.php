<?php

declare(strict_types=1);

// Run: php tests/live-listen-smoke.php (uses only an in-memory SQLite DB).
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('AMI_HOST=127.0.0.1');
putenv('AMI_PORT=1');
putenv('APP_URL=https://pbx.example.test');
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
$check($app->auth()->can('call.hangup') && !$app->auth()->can('system.apply'),
    'tenant admin may end tenant calls but cannot run a global Asterisk reload');
$gen = new App\Services\Asterisk\ConfigGenerator();
$pjsip = $gen->renderPjsipEndpoints();
$check(str_contains($pjsip, "[1001@tenant-a.example.test](endpoint_basic)\ncontext = tenant_a\nauth = 1001@tenant-a.example.test\naors = 1001@tenant-a.example.test\nidentify_by = username,auth_username")
    && str_contains($pjsip, "[1001@tenant-a.example.test]\ntype = auth\nauth_type = userpass\nusername = 1001\npassword = test-secret\nrealm = tenant-a.example.test")
    && str_contains($pjsip, '[1001@tenant-b.example.test](endpoint_basic)'), 'physical SIP usernames are isolated by tenant domain');
$monitorName = 'tenant_a_monitor_' . $user . '-web';
$check(str_contains($pjsip, '[' . $monitorName . ']') && str_contains($pjsip, 'context = panel-listen-only')
    && !str_contains($pjsip, '[tenant_a_2002-web]'), 'per-operator browser monitor endpoint is restricted and independent of extension passwords');
$check(str_contains($pjsip, "[tenant_a_1001-phone-web]\ntype = endpoint")
    && str_contains($pjsip, "callerid = \"Target\" <1001>\nwebrtc = yes")
    && substr_count($pjsip, "force_rport = yes\nrewrite_contact = yes\nrtp_symmetric = yes") >= 4,
    'WebRTC endpoints keep caller identity and symmetric NAT-safe audio');
$device = ['tenant_id' => $tid, 'mac' => '001565aabbcc', 'vendor' => 'generic', 'model' => '', 'extension_id' => $targetExtension, 'description' => 'Desk'];
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
    && str_contains($dashboard->body(), 'active-call-grid') && str_contains($dashboard->body(), 'renderRecent')
    && str_contains($dashboard->body(), 'inbound.caller')
    && str_contains($dashboard->body(), 'trunk.uniqueid === trunk.linkedid'),
    'dashboard detects inbound direction from the oldest trunk leg and renders its caller number');
$check(!str_contains($dashboard->body(), 'action="/system/apply"'),
    'tenant dashboard does not expose the global Asterisk apply action');
$check(App\Controllers\DashboardController::activeCallCount([
    ['name' => 'PJSIP/trunk-a', 'bridged' => '', 'linkedid' => 'call-1'],
    ['name' => 'PJSIP/1001-a', 'bridged' => 'PJSIP/2002-b', 'linkedid' => 'call-1'],
    ['name' => 'PJSIP/2002-b', 'bridged' => 'PJSIP/1001-a', 'linkedid' => 'call-1'],
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
    'username' => '09642359359', 'secret' => 'provider-secret', 'enabled' => 1,
]);
$trunkConfig = $gen->renderPjsipTrunks();
$check(str_contains($trunkConfig, "contact_user = 09642359359\nline = yes\nendpoint = trunk-{$trackedTrunk}\n")
    && str_contains($trunkConfig, "context = tenant_a-inbound\ntrust_id_inbound = yes")
    && str_contains($trunkConfig, "[trunk-{$trackedTrunk}-identify]\ntype = identify\nendpoint = trunk-{$trackedTrunk}\nmatch = sip.example.test"),
    'registration line and unique provider host identify inbound traffic for its tenant');
$sharedTrunk = $db->insert('trunks', [
    'tenant_id' => $other, 'name' => 'Shared provider tenant B', 'host' => 'sip.example.test',
    'username' => '09642105105', 'secret' => 'provider-secret-b', 'enabled' => 1,
]);
$sharedTrunkConfig = $gen->renderPjsipTrunks();
$sharedSuffix = substr(hash('sha256', 'sip.example.test'), 0, 12);
$check(str_contains($sharedTrunkConfig, "line = yes\nendpoint = trunk-{$trackedTrunk}\n")
    && str_contains($sharedTrunkConfig, "line = yes\nendpoint = trunk-{$sharedTrunk}\n")
    && !str_contains($sharedTrunkConfig, "[trunk-{$trackedTrunk}-identify]")
    && !str_contains($sharedTrunkConfig, "[trunk-{$sharedTrunk}-identify]")
    && str_contains($sharedTrunkConfig, "[trunk-shared-{$sharedSuffix}](trunk-basic)\ncontext = panel-inbound-shared-{$sharedSuffix}")
    && str_contains($sharedTrunkConfig, "endpoint = trunk-shared-{$sharedSuffix}\nmatch = sip.example.test"),
    'shared provider hosts use one DID-dispatch endpoint instead of an arbitrary tenant endpoint');
$trunkLabelMethod = new ReflectionMethod(App\Controllers\DashboardController::class, 'labelTrunkChannels');
$labeledChannels = $trunkLabelMethod->invoke(new App\Controllers\DashboardController(), [
    ['name' => 'PJSIP/trunk-' . $trackedTrunk . '-00000001'],
], $tid);
$check(($labeledChannels[0]['trunk_name'] ?? '') === 'Tracked', 'active calls show the configured tenant trunk name');
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
$db->insert('inbound_routes', [
    'tenant_id' => $other, 'name' => 'Tenant B direct DID', 'did_pattern' => '16661230000',
    'dest_type' => 'hangup', 'dest_id' => null, 'enabled' => 1,
]);
$db->insert('inbound_routes', [
    'tenant_id' => $tid, 'name' => 'Tenant A default inbound', 'did_pattern' => '_X.',
    'dest_type' => 'hangup', 'dest_id' => null, 'enabled' => 1,
]);
$db->insert('inbound_routes', [
    'tenant_id' => $other, 'name' => 'Tenant B default inbound', 'did_pattern' => '_X.',
    'dest_type' => 'hangup', 'dest_id' => null, 'enabled' => 1,
]);
$outboundRoute = $db->insert('outbound_routes', [
    'tenant_id' => $tid, 'name' => 'Outside via 9', 'prefix' => '9', 'match_pattern' => "_X.\n_00X.\n_+x.",
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
    && str_contains($outboundDialplan, "exten => _+X.,1,NoOp(Outbound access check)")
    && str_contains($outboundDialplan, '${PANEL_OUTBOUND_ROUTE_ID}"="' . $outboundRoute)
    && str_contains($outboundDialplan, '[tenant_a-outbound-' . $outboundRoute . ']')
    && str_contains($outboundDialplan, 'GotoIfTime(09:00-17:00,sun&mon&wed&sat,*,*,America/New_York?time-allowed)')
    && str_contains($outboundDialplan, 'Outbound calling schedule matched')
    && str_contains($outboundDialplan, '[tenant_a-timecond-' . $outboundTime . ']')
    && str_contains($outboundDialplan, 'Gosub(tenant_a-timecond-' . $outboundTime . ',s,1 )')
    && str_contains($outboundDialplan, 'exten => _9X.,1,NoOp(Outbound: Outside via 9)')
    && str_contains($outboundDialplan, 'exten => _900X.,1,NoOp(Outbound: Outside via 9)')
    && str_contains($outboundDialplan, 'exten => _9+X.,1,NoOp(Outbound: Outside via 9)')
    && str_contains($outboundDialplan, 'Dial(PJSIP/${EXTEN:1}@trunk-' . $trackedTrunk . ',30,Tt)')
    && str_contains($outboundPjsip, 'set_var = PANEL_OUTBOUND_ROUTE_ID=' . $outboundRoute), 'assigned outbound route, schedule, and prefix stripping are enforced');
$check(str_contains($outboundDialplan, "[panel-inbound-shared-{$sharedSuffix}]")
    && str_contains($outboundDialplan, 'exten => _16661230000,1,NoOp(Shared-provider DID dispatch to tenant_b)')
    && str_contains($outboundDialplan, 'Goto(tenant_b-inbound,${EXTEN},1)')
    && str_contains($outboundDialplan, 'exten => _09642359359,1,NoOp(Shared-provider registration dispatch to tenant_a)')
    && str_contains($outboundDialplan, 'Goto(tenant_a-inbound,${EXTEN},1)')
    && str_contains($outboundDialplan, 'exten => _09642105105,1,NoOp(Shared-provider registration dispatch to tenant_b)')
    && str_contains($outboundDialplan, 'Goto(tenant_b-inbound,${EXTEN},1)')
    && str_contains($outboundDialplan, 'Unmatched shared-provider DID; refusing default-tenant fallback'),
    'shared-host calls without line tokens dispatch by DID without using another tenant default route');
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
$check(($contactDetails['1001@tenant-a.example.test']['ips'] ?? null) === ['192.0.2.10', '192.0.2.11']
    && ($contactDetails['trunk-1-aor']['ips'] ?? null) === ['198.51.100.10'],
    'registered contact IPs are collected per AOR for the extensions page');

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
    'PJSIP/1001@tenant-a.example.test-00000001!tenant_a!1001!1!Up!Dial!PJSIP/trunk!Caller!acct!0!00:20!PJSIP/trunk-00000002!uid-1!linked-1' . "\n" .
    'PJSIP/2002@tenant-a.example.test-00000003!tenant_a!2002!1!Ringing!Dial!x!Caller!acct!0!00:02!!uid-2!linked-2' . "\n" .
    'PJSIP/trunk-1-00000004!tenant_a-inbound!5551234!1!Ring!Dial!PJSIP/1001!External!acct!0!00:01!PJSIP/1001@tenant-a.example.test-00000001!uid-3!linked-1' . "\n" .
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
    && $snapshot['PJSIP/1001@tenant-a.example.test-00000001']['linkedid'] === 'linked-1'
    && $snapshot['PJSIP/1001@tenant-a.example.test-00000001']['app'] === 'Dial'
    && count($tenantSnapshot) === 3, 'concise live channels include tenant trunk and extension legs');

$amiSnapshot = App\Services\Asterisk\AsteriskManager::parseCoreShowChannelEvents([
    ['Event' => 'CoreShowChannel', 'Channel' => 'PJSIP/202@tenant.test-00000001', 'Context' => 'tenant',
        'Exten' => '09611544567', 'ChannelStateDesc' => 'Up', 'Application' => 'Dial',
        'CallerIDNum' => '202', 'ConnectedLineNum' => '09611544567', 'Uniqueid' => 'uid-a',
        'Linkedid' => 'call-a', 'BridgeId' => 'bridge-a'],
    ['Event' => 'CoreShowChannel', 'Channel' => 'PJSIP/outgoing-00000002', 'Context' => 'outgoing',
        'Exten' => 's', 'ChannelStateDesc' => 'Up', 'CallerIDNum' => '09611544567',
        'Uniqueid' => 'uid-b', 'Linkedid' => 'different-linked-id', 'BridgeId' => 'bridge-a'],
    ['Event' => 'CoreShowChannelsComplete', 'EventList' => 'Complete'],
]);
$amiTenantSnapshot = App\Services\Asterisk\AsteriskManager::filterTenantChannels(
    $amiSnapshot, [['extension' => '202']], 'tenant.test', 'tenant'
);
$check(count($amiSnapshot) === 2
    && ($amiSnapshot['PJSIP/202@tenant.test-00000001']['connected'] ?? '') === '09611544567'
    && count($amiTenantSnapshot) === 2
    && App\Controllers\DashboardController::activeCallCount($amiTenantSnapshot) === 1,
    'AMI bridge identity groups extension and outgoing trunk legs as one active call');

$check(App\Controllers\DashboardController::activeCallCount([
    ['name' => 'PJSIP/202-a', 'bridged' => 'PJSIP/trunk-b'],
    ['name' => 'PJSIP/trunk-b', 'bridged' => 'PJSIP/202-a'],
]) === 1, 'reciprocal bridge peers count as one call without AMI identifiers');

$db->insert('cdr', [
    'tenant_id' => $tid, 'calldate' => '2026-11-06 10:00:00', 'src' => '1001', 'dst' => '09611544567',
    'disposition' => 'ANSWERED', 'billsec' => 42, 'uniqueid' => 'webphone-history-1',
]);
$phoneLogin = (new App\Controllers\WebPhoneController())->connect(new App\Core\Request(
    'POST', '/web-phone/connect', [], ['extension' => '1001', 'secret' => 'test-secret'], ['REMOTE_ADDR' => '192.0.2.44']
));
$phoneConfig = json_decode($phoneLogin->body(), true);
$check($phoneLogin->status() === 200 && ($phoneConfig['username'] ?? '') === 'tenant_a_1001-phone-web'
    && !isset($phoneConfig['password']) && count($phoneConfig['contacts'] ?? []) === 1
    && ($phoneConfig['history'][0]['number'] ?? '') === '09611544567'
    && ($phoneConfig['history'][0]['direction'] ?? '') === 'outgoing',
    'public Web Phone login returns tenant contacts and extension CDR history without exposing the password');
$_SERVER['HTTP_HOST'] = 'tenant-b.example.test';
$tenantUrlPhone = (new App\Controllers\WebPhoneController())->connect(new App\Core\Request(
    'POST', '/web-phone/connect', [], ['extension' => '1001', 'secret' => 'test-secret3'], ['REMOTE_ADDR' => '192.0.2.45']
));
$check((json_decode($tenantUrlPhone->body(), true)['username'] ?? '') === 'tenant_b_1001-phone-web',
    'tenant Web Phone URL accepts only that panel tenant extension');
$primaryTenant = $db->insert('tenants', [
    'name' => 'Primary URL tenant', 'context' => 'primary_url', 'domain' => 'pbx.example.test', 'enabled' => 1,
]);
$db->insert('extensions', [
    'tenant_id' => $primaryTenant, 'extension' => '3001', 'name' => 'Primary phone',
    'secret' => 'primary-secret', 'enabled' => 1,
]);
$app->auth()->logout();
$_SERVER['HTTP_HOST'] = 'pbx.example.test';
$primaryUrlPhone = (new App\Controllers\WebPhoneController())->connect(new App\Core\Request(
    'POST', '/web-phone/connect', [], ['extension' => '3001', 'secret' => 'primary-secret'], ['REMOTE_ADDR' => '192.0.2.46']
));
$check($primaryUrlPhone->status() === 200
    && (json_decode($primaryUrlPhone->body(), true)['username'] ?? '') === 'primary_url_3001-phone-web',
    'Web Phone honors a tenant domain even when it is also the primary panel URL');
$failedLogin = null;
for ($attempt = 1; $attempt <= 5; $attempt++) {
    $failedLogin = (new App\Controllers\WebPhoneController())->connect(new App\Core\Request(
        'POST', '/web-phone/connect', [], ['extension' => '9999', 'secret' => 'wrong'], ['REMOTE_ADDR' => '192.0.2.47']
    ));
}
$blockedLogin = (new App\Controllers\WebPhoneController())->connect(new App\Core\Request(
    'POST', '/web-phone/connect', [], ['extension' => '9999', 'secret' => 'wrong'], ['REMOTE_ADDR' => '192.0.2.47']
));
$check($failedLogin?->status() === 401 && $blockedLogin->status() === 429
    && (int) $db->scalar('SELECT attempts FROM webphone_login_throttles WHERE ip = ?', ['192.0.2.47']) === 5,
    'Web Phone brute-force throttling persists in the database across controller instances');
$app->auth()->login($user);
unset($_SERVER['HTTP_HOST']);

$controller = new App\Controllers\LiveListenController();
$monitorSession = json_decode($controller->browserSession(new App\Core\Request('POST', '/active-calls/browser-session'))->body(), true);
$check(($monitorSession['username'] ?? '') === $monitorName
    && strlen((string) ($monitorSession['password'] ?? '')) >= 32
    && ($monitorSession['password'] ?? '') !== 'test-secret',
    'authorized Active Calls browser listening uses a dedicated credential without asking for an extension password');
$targetIdentity = new ReflectionMethod(App\Controllers\LiveListenController::class, 'targetExtension');
$check($targetIdentity->invoke($controller, '1001@tenant-a.example.test', 'tenant-a.example.test', 'tenant_a') === '1001'
    && $targetIdentity->invoke($controller, 'tenant_a_1001-phone-web', 'tenant-a.example.test', 'tenant_a') === '1001'
    && $targetIdentity->invoke($controller, 'tenant_b_1001-phone-web', 'tenant-a.example.test', 'tenant_a') === '',
    'live listen maps desk-phone and Web Phone channels without crossing tenants');
$page = $controller->index(new App\Core\Request('GET', '/active-calls'));
$check($page->status() === 200 && str_contains($page->body(), 'Active Calls')
    && str_contains($page->body(), 'Listen on an extension')
    && str_contains($page->body(), 'active-calls-grid'),
    'Active Calls merges Grandstream-style monitoring with browser and extension listening');
$calls = $controller->calls(new App\Core\Request('GET', '/active-calls/feed'));
$check($calls->status() === 200 && isset(json_decode($calls->body(), true)['calls']),
    'Active Calls polling feed responds');
$check($controller->legacy(new App\Core\Request('GET', '/live-listen'))->headers()['Location'] === '/active-calls',
    'legacy Live Listen URL redirects to Active Calls');
$request = static fn (string $channel): App\Core\Request => new App\Core\Request(
    'POST', '/active-calls/listen', [], ['channel' => $channel]
);
$check($controller->start($request('PJSIP/1001@tenant-b.example.test-00000001'))->status() === 403, 'cannot listen to another tenant');
$foreignHangup = new App\Core\Request('POST', '/active-calls/hangup', [], ['channel' => 'PJSIP/1001@tenant-b.example.test-00000001']);
$check($controller->hangup($foreignHangup)->status() === 403, 'cannot hang up another tenant call');
$check($controller->start($request('PJSIP/1001@tenant-a.example.test-00000001'))->status() === 409, 'stale/offline target cannot be originated');
$db->insert('tenants', ['name' => 'Ambiguous', 'context' => 'tenant-a', 'enabled' => 1]);
$check($controller->start($request('PJSIP/1001@tenant-a.example.test-00000001'))->status() === 409, 'ambiguous flattened contexts fail closed');
$db->update('users', $user, ['role' => 'user']);
$app->auth()->login($user);
$check(!$app->auth()->can('call.listen'), 'ordinary panel user cannot listen');
echo "All live-listen smoke checks passed.\n";
