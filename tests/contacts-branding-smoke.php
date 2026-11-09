<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('APP_URL=https://pbx.example.test');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) $db->exec($sql);
$tenantA = $db->insert('tenants', ['name' => 'A', 'context' => 'a', 'domain' => 'a.example.test', 'enabled' => 1]);
$tenantB = $db->insert('tenants', ['name' => 'B', 'context' => 'b', 'domain' => 'b.example.test', 'enabled' => 1]);
$user = $db->insert('users', ['tenant_id' => $tenantA, 'username' => 'admin', 'password' => password_hash('pass', PASSWORD_DEFAULT), 'role' => 'admin', 'active' => 1]);
$app->auth()->login($user);

$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
};
$check($app->auth()->can('contact.view') && $app->auth()->can('contact.manage'), 'tenant admins receive contact permissions');

$controller = new App\Controllers\ContactController();
$created = $controller->store(new App\Core\Request('POST', '/contacts', [], [
    'name' => 'Alice Customer', 'phone' => '+1 (555) 123-4567', 'email' => 'alice@example.test', 'company' => 'Example',
]));
$contact = $db->one('SELECT * FROM contacts WHERE tenant_id = ?', [$tenantA]);
$check($created->status() === 302 && ($contact['phone'] ?? '') === '+15551234567', 'manual contacts normalize phone numbers and stay tenant scoped');

$db->insert('contacts', ['tenant_id' => $tenantB, 'name' => 'Tenant B Only', 'phone' => '+19995550123']);
$foreignRequest = new App\Core\Request('GET', '/contacts/2/edit');
$foreignRequest->params['id'] = 2;
$foreignEdit = $controller->edit($foreignRequest);
$check($foreignEdit->status() === 302 && (int) $db->scalar('SELECT COUNT(*) FROM contacts WHERE tenant_id = ?', [$tenantB]) === 1, 'contact CRUD cannot access another tenant row');

$csvPath = BASE_PATH . '/storage/cache/contacts-smoke.csv';
@mkdir(dirname($csvPath), 0775, true);
file_put_contents($csvPath, "Name,Phone,Email,Company,Notes\nBob,+15557654321,bob@example.test,Example,Imported\nDuplicate,+15551234567,,,\nInvalid,not-a-phone,,,\n");
$import = $controller->import(new App\Core\Request('POST', '/contacts/import', [], [], [], [
    'contacts_file' => ['name' => 'contacts.csv', 'tmp_name' => $csvPath, 'size' => filesize($csvPath), 'error' => UPLOAD_ERR_OK],
]));
$check($import->status() === 302 && (int) $db->scalar('SELECT COUNT(*) FROM contacts WHERE tenant_id = ?', [$tenantA]) === 2
    && (int) $db->scalar("SELECT COUNT(*) FROM contacts WHERE tenant_id = ? AND name = 'Bob'", [$tenantA]) === 1,
    'CSV import inserts valid rows and skips duplicate and invalid rows');
$template = $controller->template(new App\Core\Request('GET', '/contacts/import/template'));
$check(str_starts_with($template->body(), "\xEF\xBB\xBFName,Phone") && str_contains($template->headers()['Content-Disposition'] ?? '', '.csv'), 'Excel-compatible UTF-8 CSV template downloads with a BOM');

$extension = $db->insert('extensions', ['tenant_id' => $tenantA, 'extension' => '1001', 'name' => 'Alice Extension', 'secret' => 'secret', 'enabled' => 1]);
$db->insert('extensions', ['tenant_id' => $tenantB, 'extension' => '9001', 'name' => 'Alice Other Tenant', 'secret' => 'secret', 'enabled' => 1]);
$db->insert('devices', ['tenant_id' => $tenantA, 'extension_id' => $extension, 'mac' => '001565abcdef', 'vendor' => 'Alice Phone', 'provision_token' => bin2hex(random_bytes(32)), 'enabled' => 1]);
$db->insert('trunks', ['tenant_id' => $tenantA, 'name' => 'Alice Trunk', 'host' => 'alice.sip.example.test', 'transport' => 'tcp', 'enabled' => 1]);
$search = (new App\Controllers\SearchController())->index(new App\Core\Request('GET', '/search', ['q' => 'Alice']));
$searchRows = json_decode($search->body(), true)['results'] ?? [];
$searchKinds = array_column($searchRows, 'kind');
$check($search->status() === 200 && in_array('Extension', $searchKinds, true) && in_array('Contact', $searchKinds, true)
    && in_array('Device', $searchKinds, true) && in_array('Trunk', $searchKinds, true)
    && !str_contains($search->body(), 'Other Tenant'), 'global search returns useful tenant-scoped entities without cross-tenant results');

$searchController = new App\Controllers\SearchController();
$tlsSearch = $searchController->index(new App\Core\Request('GET', '/search', ['q' => 'TLS']));
$tlsRows = json_decode($tlsSearch->body(), true)['results'] ?? [];
$tlsSetting = array_values(array_filter($tlsRows, static fn (array $row): bool => ($row['kind'] ?? '') === 'Setting'));
$check($tlsSetting === [], 'global search hides Super Admin SIP settings from tenant administrators');
$brandingSearch = $searchController->index(new App\Core\Request('GET', '/search', ['q' => 'branding']));
$check(str_contains($brandingSearch->body(), '/system/settings#panel-branding'), 'global search links permitted users directly to matching Settings sections case-insensitively');
$db->insert('settings', ['tenant_id' => $tenantA, 'key' => 'voicemail_email_subject', 'value' => 'PrivateNeedleValue']);
$privateSearch = $searchController->index(new App\Core\Request('GET', '/search', ['q' => 'PrivateNeedleValue']));
$check(!str_contains($privateSearch->body(), 'PrivateNeedleValue') && !str_contains($privateSearch->body(), 'Setting'), 'global search never indexes stored Settings values');
$settingsView = file_get_contents(BASE_PATH . '/themes/default/views/system/settings.php');
$check(str_contains($settingsView, 'id="sip-transports"') && str_contains($settingsView, 'id="panel-branding"')
    && str_contains($settingsView, 'id="business-details"') && str_contains($settingsView, 'id="voicemail-email"')
    && str_contains($settingsView, 'id="call-recording"'), 'Settings search targets stable section anchors');

$restrictedUser = $db->insert('users', ['tenant_id' => $tenantA, 'username' => 'restricted', 'password' => password_hash('pass', PASSWORD_DEFAULT), 'role' => 'user', 'active' => 1]);
$app->auth()->login($restrictedUser);
$restrictedSearch = $searchController->index(new App\Core\Request('GET', '/search', ['q' => 'TLS']));
$restrictedRows = json_decode($restrictedSearch->body(), true)['results'] ?? [];
$check(!in_array('Setting', array_column($restrictedRows, 'kind'), true), 'global search hides Settings sections without system settings permission');
$superadmin = $db->insert('users', ['tenant_id' => null, 'username' => 'root-search', 'password' => password_hash('pass', PASSWORD_DEFAULT), 'role' => 'superadmin', 'active' => 1]);
$app->auth()->login($superadmin);
$globalSettingsSearch = $searchController->index(new App\Core\Request('GET', '/search', ['q' => 'TLS']));
$check(str_contains($globalSettingsSearch->body(), '/system/settings#sip-transports'), 'global Settings search works without an active tenant');
$app->auth()->login($user);

$db->insert('settings', ['tenant_id' => null, 'key' => 'panel_name', 'value' => 'Global Brand']);
$db->insert('settings', ['tenant_id' => $tenantA, 'key' => 'panel_name', 'value' => 'Tenant A Brand']);
$branding = App\Services\Branding::current($tenantA);
$check(($branding['name'] ?? '') === 'Tenant A Brand' && ($branding['initial'] ?? '') === 'T', 'tenant panel name overrides the global branding fallback');
$errorPage = App\Core\View::getInstance()->render('errors/404', ['path' => '/missing']);
$check(str_contains($errorPage, 'Tenant A Brand'), 'directly rendered error layouts resolve branding safely');

$relativeLogo = 'branding/tenant-' . $tenantA . '/0123456789abcdef0123456789abcdef.png';
$logoPath = BASE_PATH . '/storage/' . $relativeLogo;
@mkdir(dirname($logoPath), 0775, true);
file_put_contents($logoPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
$db->insert('settings', ['tenant_id' => $tenantA, 'key' => 'panel_logo', 'value' => $relativeLogo]);
$logo = (new App\Controllers\BrandingController())->logo(new App\Core\Request('GET', '/branding/logo'));
$check($logo->status() === 200 && ($logo->headers()['Content-Type'] ?? '') === 'image/png', 'branding logo is served only from validated tenant storage');

@unlink($csvPath);
@unlink($logoPath);
@rmdir(dirname($logoPath));
echo "All contact and branding checks passed.\n";
