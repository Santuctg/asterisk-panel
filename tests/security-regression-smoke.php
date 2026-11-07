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

echo "All emergency security regression checks passed.\n";
