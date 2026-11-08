<?php

declare(strict_types=1);

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

$check = static function (bool $ok, string $label): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    echo 'PASS: ' . $label . PHP_EOL;
};

$tenantA = $db->insert('tenants', ['name' => 'Tenant A', 'context' => 'tenant_a', 'domain' => 'a.test', 'enabled' => 1]);
$tenantB = $db->insert('tenants', ['name' => 'Tenant B', 'context' => 'tenant_b', 'domain' => 'b.test', 'enabled' => 1]);
$adminA = $db->insert('users', [
    'tenant_id' => $tenantA, 'username' => 'admin-a', 'password' => password_hash('test-only', PASSWORD_DEFAULT),
    'role' => 'admin', 'active' => 1,
]);
$extensionA = $db->insert('extensions', [
    'tenant_id' => $tenantA, 'extension' => '1001', 'name' => 'Tenant A phone', 'secret' => 'a-secret', 'enabled' => 1,
]);
$extensionB = $db->insert('extensions', [
    'tenant_id' => $tenantB, 'extension' => '2001', 'name' => 'Tenant B phone', 'secret' => 'b-secret',
    'vm_enabled' => 0, 'enabled' => 1,
]);
$trunkB = $db->insert('trunks', [
    'tenant_id' => $tenantB, 'name' => 'Tenant B carrier', 'host' => 'carrier-b.test',
    'username' => 'tenant-b', 'secret' => 'trunk-secret', 'enabled' => 1,
]);

$_SERVER['HTTP_HOST'] = 'a.test';
$_SESSION['active_tenant'] = $tenantA;
$app->auth()->login($adminA);

(new App\Controllers\DeviceController())->store(new App\Core\Request('POST', '/devices', [], [
    'extension_id' => $extensionB, 'mac' => '00:11:22:33:44:55', 'vendor' => 'generic', 'enabled' => '1',
]));
$check((int) $db->scalar('SELECT COUNT(*) FROM devices') === 0,
    'a device cannot reference another tenant extension');

(new App\Controllers\VoicemailController())->store(new App\Core\Request('POST', '/voicemail', [], [
    'extension_id' => $extensionB, 'pin' => '123456', 'name' => 'Cross-tenant mailbox',
    'timezone' => 'UTC', 'enabled' => '1',
]));
$check((int) $db->scalar('SELECT COUNT(*) FROM voicemail') === 0
    && (int) $db->scalar('SELECT vm_enabled FROM extensions WHERE id = ?', [$extensionB]) === 0,
    'a voicemail box cannot reference another tenant extension');

(new App\Controllers\RouteController())->storeOutbound(new App\Core\Request('POST', '/routes/outbound', [], [
    'name' => 'Cross-tenant carrier route', 'match_pattern' => '_X.', 'trunk_id' => $trunkB,
    'schedules' => '[]', 'enabled' => '1',
]));
$check((int) $db->scalar('SELECT COUNT(*) FROM outbound_routes') === 0,
    'an outbound route cannot reference another tenant trunk');

(new App\Controllers\IvrController())->store(new App\Core\Request('POST', '/ivr', [], [
    'extension' => '301', 'name' => 'Cross-tenant IVR', 'key' => ['1'],
    'key_type' => ['extension'], 'key_dest' => [$extensionB], 'timeout_action' => '', 'enabled' => '1',
]));
$check((int) $db->scalar('SELECT COUNT(*) FROM ivr_menus WHERE tenant_id = ?', [$tenantA]) === 0,
    'an IVR key cannot reference another tenant destination');

(new App\Controllers\RingGroupController())->store(new App\Core\Request('POST', '/ring-groups', [], [
    'extension' => '1001', 'name' => 'Colliding group', 'strategy' => 'ringall', 'timeout' => '30', 'enabled' => '1',
]));
$check((int) $db->scalar('SELECT COUNT(*) FROM ring_groups WHERE tenant_id = ?', [$tenantA]) === 0,
    'one tenant cannot reuse an extension number for another dialplan feature');

$provisionToken = bin2hex(random_bytes(32));
$db->insert('devices', [
    'tenant_id' => $tenantA, 'extension_id' => $extensionA, 'mac' => '001565aabbcc',
    'vendor' => 'generic', 'provision_token' => $provisionToken, 'enabled' => 1,
]);
$provisioning = new App\Services\ProvisioningService();
[$deniedProvision] = $provisioning->serve('001565aabbcc', str_repeat('0', 64));
[$allowedProvision] = $provisioning->serve('001565aabbcc', $provisionToken);
$check($deniedProvision === '' && str_contains($allowedProvision, 'password=a-secret')
    && str_contains($provisioning->provisionUrl($db->one('SELECT * FROM devices WHERE mac = ?', ['001565aabbcc'])), '/prov/' . $provisionToken . '/'),
    'device provisioning requires a per-device random capability token');
$db->update('extensions', $extensionA, ['secret' => 'rotated-secret']);
[$refreshedProvision] = $provisioning->serve('001565aabbcc', $provisionToken);
$check(str_contains($refreshedProvision, 'password=rotated-secret') && !str_contains($refreshedProvision, 'password=a-secret'),
    'provisioning cache refreshes after a credential change without relying on timestamps');
$db->update('devices', (int) $db->scalar('SELECT id FROM devices WHERE mac = ?', ['001565aabbcc']), ['extension_id' => $extensionB]);
[$crossTenantProvision] = $provisioning->serve('001565aabbcc', $provisionToken);
$check(!str_contains($crossTenantProvision, 'b-secret') && !str_contains($crossTenantProvision, 'username=2001'),
    'legacy cross-tenant device links cannot disclose another tenant SIP credentials');
$db->update('extensions', $extensionA, ['secret' => 'a-secret']);

// Direct database writes model legacy/imported data and prove generation is safe too.
$payload = "\n[evil]\ninjected_directive = yes\n";
$db->update('tenants', $tenantA, ['name' => 'Tenant A' . $payload]);
$db->update('extensions', $extensionA, ['name' => 'Desk' . $payload, 'cid_name' => 'Caller' . $payload]);
$trunkA = $db->insert('trunks', [
    'tenant_id' => $tenantA, 'name' => 'Carrier' . $payload, 'host' => 'carrier.test' . $payload,
    'username' => 'account' . $payload, 'secret' => 'secret' . $payload,
    'from_domain' => 'carrier.test' . $payload, 'cid' => '1001' . $payload, 'enabled' => 1,
]);
$db->insert('voicemail', [
    'tenant_id' => $tenantA, 'extension_id' => $extensionA, 'pin' => '1234' . $payload,
    'name' => 'Mailbox' . $payload, 'email' => 'a@example.test' . $payload,
    'pager_email' => 'pager@example.test' . $payload, 'vm_password' => 'vm' . $payload,
    'timezone' => 'UTC', 'enabled' => 1,
]);
$db->insert('inbound_routes', [
    'tenant_id' => $tenantA, 'name' => 'Inbound' . $payload, 'did_pattern' => '_X.' . $payload,
    'dest_type' => 'extension', 'dest_id' => $extensionA, 'enabled' => 1,
]);
$db->insert('outbound_routes', [
    'tenant_id' => $tenantA, 'name' => 'Outbound' . $payload, 'match_pattern' => '_X.' . $payload,
    'trunk_id' => $trunkA, 'cid_override' => '1001' . $payload, 'enabled' => 1,
]);
$queue = $db->insert('queues', [
    'tenant_id' => $tenantA, 'extension' => '600', 'name' => 'Queue' . $payload,
    'agent_announce' => 'beep' . $payload, 'enabled' => 1,
]);
$db->insert('queue_agents', ['queue_id' => $queue, 'extension_id' => $extensionA, 'penalty' => 0]);
$db->insert('moh_classes', [
    'tenant_id' => $tenantA, 'name' => 'Music' . $payload, 'directory' => '/tmp/music' . $payload,
]);
$db->insert('parking_lots', [
    'tenant_id' => $tenantA, 'name' => 'Parking' . $payload, 'extension' => '700',
    'parked_range' => '701-750' . $payload, 'return_extension' => '1001', 'enabled' => 1,
]);
$db->insert('conferences', [
    'tenant_id' => $tenantA, 'extension' => '800', 'name' => 'Conference' . $payload,
    'pin' => '9876' . $payload, 'enabled' => 1,
]);

$generator = new App\Services\Asterisk\ConfigGenerator();
$generated = implode("\n", [
    $generator->renderPjsipEndpoints(), $generator->renderPjsipTrunks(), $generator->renderVoicemail(),
    $generator->renderDialplan(), $generator->renderQueues(), $generator->renderParking(),
    $generator->renderMusicOnHold(), $generator->renderConfBridge(),
]);
$check(!preg_match('/(?:^|\R)(?:\[evil\]|injected_directive\s*=\s*yes)(?:\R|$)/', $generated),
    'generated Asterisk files cannot gain injected sections or directives');
$check(!str_contains($generated, "\0") && !preg_match('/[\x01-\x08\x0B\x0C\x0E-\x1F\x7F]/', $generated),
    'generated Asterisk files contain no injected control characters');

$nginx = (string) file_get_contents(BASE_PATH . '/resources/deploy/nginx-https.conf.template');
$check(str_contains($nginx, 'microphone=(self)') && !str_contains($nginx, 'microphone=()'),
    'main HTTPS policy allows same-origin Web Phone microphone access');

$db->insert('audit_log', [
    'tenant_id' => $tenantA, 'user_id' => $adminA, 'action' => 'tenant_a.visible_action', 'created_at' => date('Y-m-d H:i:s'),
]);
$db->insert('audit_log', [
    'tenant_id' => $tenantB, 'user_id' => null, 'action' => 'tenant_b.private_action', 'created_at' => date('Y-m-d H:i:s'),
]);
$system = new App\Controllers\SystemController();
$auditPage = $system->auditLog(new App\Core\Request('GET', '/system/audit'));
$auditFeed = json_decode($system->auditFeed(new App\Core\Request('GET', '/system/audit-feed', ['since' => 0]))->body(), true);
$check(str_contains($auditPage->body(), 'tenant_a.visible_action')
    && !str_contains($auditPage->body(), 'tenant_b.private_action')
    && count($auditFeed['rows'] ?? []) === 1,
    'tenant audit pages and feeds cannot disclose another tenant actions');
$check($system->apply(new App\Core\Request('POST', '/system/apply', [], ['feature' => 'all']))->status() === 403,
    'tenant admins cannot trigger a global Asterisk regeneration or reload');
$tenantStats = json_decode($system->serverStats(new App\Core\Request('GET', '/system/server-stats'))->body(), true);
$check(array_key_exists('cpu', $tenantStats['current'] ?? []) && $tenantStats['current']['cpu'] === null
    && ($tenantStats['series'] ?? null) === [],
    'tenant status endpoint does not expose panel host resource history');
$tenantContacts = App\Services\Asterisk\AsteriskManager::filterTenantContacts([
    ['uri' => '1001@a.test/sip:1001@192.0.2.10', 'status' => 'Avail'],
    ['uri' => '2001@b.test/sip:2001@192.0.2.20', 'status' => 'Avail'],
    ['uri' => 'trunk-99-aor/sip:198.51.100.20', 'status' => 'Avail'],
], [['extension' => '1001']], 'a.test', 'tenant_a');
$check(count($tenantContacts) === 1 && str_starts_with($tenantContacts[0]['uri'], '1001@a.test/'),
    'tenant status contact filtering excludes other endpoint and trunk registrations');

$secretMarker = 'SIP-SECRET-DO-NOT-RENDER-7f29';
$db->update('extensions', $extensionA, ['secret' => $secretMarker]);
$editRequest = new App\Core\Request('GET', '/extensions/' . $extensionA . '/edit');
$editRequest->params = ['id' => $extensionA];
$extensionEdit = (new App\Controllers\ExtensionController())->edit($editRequest);
$db->update('trunks', $trunkA, ['secret' => $secretMarker]);
$routesPage = (new App\Controllers\RouteController())->index(new App\Core\Request('GET', '/routes', ['tab' => 'trunks']));
$check(!str_contains($extensionEdit->body(), $secretMarker) && !str_contains($routesPage->body(), $secretMarker),
    'stored SIP credentials are never serialized into extension or trunk edit HTML');
$extensionRevealRequest = new App\Core\Request('POST', '/extensions/' . $extensionA . '/reveal-secret');
$extensionRevealRequest->params = ['id' => $extensionA];
$extensionReveal = (new App\Controllers\ExtensionController())->revealSecret($extensionRevealRequest);
$trunkRevealRequest = new App\Core\Request('POST', '/routes/trunks/' . $trunkA . '/reveal-secret');
$trunkRevealRequest->params = ['id' => $trunkA];
$trunkReveal = (new App\Controllers\RouteController())->revealTrunkSecret($trunkRevealRequest);
$foreignRevealRequest = new App\Core\Request('POST', '/extensions/' . $extensionB . '/reveal-secret');
$foreignRevealRequest->params = ['id' => $extensionB];
$foreignReveal = (new App\Controllers\ExtensionController())->revealSecret($foreignRevealRequest);
$check((json_decode($extensionReveal->body(), true)['secret'] ?? '') === $secretMarker
    && (json_decode($trunkReveal->body(), true)['secret'] ?? '') === $secretMarker
    && str_contains((string) ($extensionReveal->headers()['Cache-Control'] ?? ''), 'no-store')
    && $foreignReveal->status() === 404,
    'credential reveal is on-demand, non-cacheable, and tenant scoped');
$disableRequest = new App\Core\Request('POST', '/extensions/' . $extensionA, [], [
    'extension' => '1001', 'name' => 'Tenant A phone', 'secret' => '',
    'enabled' => '0', 'vm_enabled' => '0', 'vm_on_busy' => '0',
    'vm_on_noanswer' => '0', 'follow_me' => '0',
]);
$disableRequest->params = ['id' => $extensionA];
(new App\Controllers\ExtensionController())->update($disableRequest);
$disabledExtension = $db->one('SELECT enabled, secret FROM extensions WHERE id = ?', [$extensionA]);
$check((int) ($disabledExtension['enabled'] ?? 1) === 0
    && ($disabledExtension['secret'] ?? '') === $secretMarker,
    'unchecked enabled value persists as off without rotating an unchanged extension password');

$oldInput = new class extends App\Controllers\BaseController {
    public function capture(App\Core\Request $request): void { $this->stashOld($request); }
};
$oldInput->capture(new App\Core\Request('POST', '/example', [], [
    'name' => 'Safe name', 'password' => 'panel-pass', 'secret' => 'sip-pass',
    'vm_pin' => '1234', '_token' => 'csrf-value',
]));
$check(($_SESSION['__old']['name'] ?? '') === 'Safe name'
    && !isset($_SESSION['__old']['password'], $_SESSION['__old']['secret'], $_SESSION['__old']['vm_pin'], $_SESSION['__old']['_token']),
    'validation old-input state excludes passwords, SIP secrets, PINs, and CSRF tokens');

$csrfResponse = App\Core\Csrf::middleware(
    new App\Core\Request('POST', '/unsafe', [], ['value' => 'x'], [
        'HTTP_REFERER' => 'https://evil.example/phish', 'HTTP_HOST' => 'a.test',
    ]),
    static fn (): App\Core\Response => App\Core\Response::text('unexpected')
);
$check(($csrfResponse->headers()['Location'] ?? '') === '/dashboard',
    'invalid CSRF requests cannot redirect to an external Referer');

$headers = App\Core\Response::html('ok')->headers();
$check(str_contains((string) ($headers['Content-Security-Policy'] ?? ''), "frame-ancestors 'none'")
    && ($headers['X-Content-Type-Options'] ?? '') === 'nosniff',
    'application responses carry CSP and browser hardening headers');

$patternProbe = new class extends App\Controllers\BaseController {
    public function pattern(string $value): string { return $this->likePattern($value); }
};
$literalPattern = $patternProbe->pattern('%_!');
$check($literalPattern === '%!%!_!!%' && (int) $db->scalar("SELECT CASE WHEN '100%' LIKE ? ESCAPE '!' THEN 1 ELSE 0 END", [$patternProbe->pattern('%')]) === 1
    && (int) $db->scalar("SELECT CASE WHEN '1000' LIKE ? ESCAPE '!' THEN 1 ELSE 0 END", [$patternProbe->pattern('%')]) === 0,
    'search treats user wildcard characters literally while retaining contains matching');

echo "All security regression checks passed.\n";
