<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('AMI_PORT=1');
putenv('AMI_TIMEOUT=1');
require BASE_PATH . '/app/bootstrap.php';

$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: {$label}\n";
};

$keys = App\Services\Asterisk\AsteriskManager::extensionContactKeys('1001', 'tenant.test', 'tenant_a');
$check($keys === ['1001@tenant.test', 'tenant_a_1001-phone-web', 'tenant_a_1001-web'],
    'canonical extension keys include desk phone and both Web Phone forms');

$registered = ['enabled' => 1];
$disabled = ['enabled' => 0];
$live = ['status' => 'Avail', 'devices' => 1];
$down = ['status' => null, 'devices' => 0];
$check(App\Controllers\ExtensionController::registrationMatches($registered, $live, 'registered', true),
    'registered filter accepts an enabled live extension');
$check(App\Controllers\ExtensionController::registrationMatches($registered, $down, 'unregistered', true),
    'unregistered filter accepts an enabled extension without a contact');
$check(App\Controllers\ExtensionController::registrationMatches($disabled, $down, 'disabled', false),
    'disabled filter works without AMI');
$check(!App\Controllers\ExtensionController::registrationMatches($registered, $down, 'unregistered', false),
    'AMI outage never guesses unregistered state');

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) $db->exec($sql);
$tenant = $db->insert('tenants', [
    'name' => 'Registration Tenant', 'context' => 'reg_tenant', 'domain' => 'reg.example.test',
    'timezone' => 'UTC', 'enabled' => 1,
]);
$foreignTenant = $db->insert('tenants', [
    'name' => 'Foreign Tenant', 'context' => 'foreign', 'timezone' => 'UTC', 'enabled' => 1,
]);
$admin = $db->insert('users', [
    'tenant_id' => null, 'username' => 'registration-admin',
    'password' => password_hash('test-only', PASSWORD_DEFAULT), 'role' => 'superadmin', 'active' => 1,
]);
$app->auth()->login($admin);
$_SESSION['active_tenant'] = $tenant;

$extensionIds = [];
foreach (range(101, 107) as $number) {
    $extensionIds[$number] = $db->insert('extensions', [
        'tenant_id' => $tenant, 'extension' => (string) $number, 'name' => 'Desk ' . $number,
        'secret' => 'secret-' . $number, 'cid_name' => 'Caller ' . $number, 'enabled' => 0,
    ]);
}
$db->insert('extensions', [
    'tenant_id' => $foreignTenant, 'extension' => '999', 'name' => 'Foreign Desk',
    'secret' => 'foreign-secret', 'enabled' => 0,
]);
$db->insert('voicemail', [
    'tenant_id' => $foreignTenant, 'extension_id' => $extensionIds[102], 'pin' => '9999',
    'name' => 'Cross-tenant mailbox', 'email' => 'foreign-mailbox@example.test',
]);
$db->insert('devices', [
    'tenant_id' => $foreignTenant, 'extension_id' => $extensionIds[101],
    'mac' => 'AABBCCDDEEFF', 'vendor' => 'foreign-vendor',
    'provision_token' => 'cross-tenant-device',
]);
foreach ([1, 2] as $suffix) {
    $db->insert('voicemail', [
        'tenant_id' => $tenant, 'extension_id' => $extensionIds[101], 'pin' => '12' . $suffix,
        'name' => 'Mailbox ' . $suffix, 'email' => 'mail' . $suffix . '@example.test',
    ]);
    $db->insert('devices', [
        'tenant_id' => $tenant, 'extension_id' => $extensionIds[101],
        'mac' => sprintf('0011223344%02d', $suffix), 'vendor' => 'generic',
        'provision_token' => 'registration-device-' . $suffix,
    ]);
}

$extensionHtml = (new App\Controllers\ExtensionController())->index(new App\Core\Request(
    'GET', '/extensions', ['registration' => 'disabled', 'page' => '2', 'per' => '5']
))->body();
$check(str_contains($extensionHtml, '7 extensions') && str_contains($extensionHtml, 'Desk 106')
    && str_contains($extensionHtml, 'Desk 107') && !str_contains($extensionHtml, 'Desk 105'),
    'database-backed filtering keeps duplicate related rows out of totals and page membership');
$check(!str_contains($extensionHtml, 'Foreign Desk') && !str_contains($extensionHtml, 'foreign-secret'),
    'Extension filtering remains tenant-scoped and never renders foreign secrets');
$check(str_contains($extensionHtml, 'name="per" value="5"')
    && str_contains($extensionHtml, 'registration=disabled&amp;per=5&amp;page=1'),
    'Extension filter submission and pagination preserve normalized per-page state');
$firstPageHtml = (new App\Controllers\ExtensionController())->index(new App\Core\Request(
    'GET', '/extensions', ['registration' => 'disabled', 'page' => '1', 'per' => '5']
))->body();
$check(substr_count($firstPageHtml, 'data-modal-open="extensionMonitor' . $extensionIds[101] . '"') === 1
    && str_contains($firstPageHtml, '00:11:22:33:44:01') && !str_contains($firstPageHtml, 'AA:BB:CC:DD:EE:FF'),
    'page hydration renders one extension row and deterministically selects its first related device');
$desk102Start = strpos($firstPageHtml, '<strong class="mono">102</strong>');
$desk102Row = $desk102Start === false ? '' : substr($firstPageHtml, $desk102Start, 900);
$check(str_contains($desk102Row, '<span class="badge gray">Off</span>'),
    'page hydration ignores a voicemail row whose tenant does not own the extension');

$dashboard = file_get_contents(BASE_PATH . '/themes/default/views/dashboard/index.php');
$extensions = file_get_contents(BASE_PATH . '/themes/default/views/extensions/index.php');
$check(str_contains($dashboard, "'Registered phones', '/extensions?registration=registered'"),
    'Dashboard Registered phones opens the registered extension filter');
$check(str_contains($extensions, 'name="registration"') && str_contains($extensions, 'Unregistered')
    && str_contains($extensions, 'http_build_query'), 'Extensions UI renders and preserves registration filters');

echo "All extension registration filter checks passed.\n";
