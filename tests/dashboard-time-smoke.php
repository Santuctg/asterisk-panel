<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite'); putenv('DB_NAME=:memory:');
putenv('APP_TIMEZONE=Asia/Dhaka');
putenv('APP_PRIMARY_HOST=panel.example.test');
require BASE_PATH . '/app/bootstrap.php';

use App\Core\App;

$app = App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) {
    $db->exec($sql);
}

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$tid = $db->insert('tenants', [
    'name' => 'Timezone Tenant', 'context' => 'tztenant', 'domain' => null,
    'timezone' => 'Asia/Dhaka', 'enabled' => 1,
]);
$userId = $db->insert('users', [
    'tenant_id' => null, 'username' => 'tzadmin', 'email' => 'tz@example.test',
    'password' => password_hash('test-password', PASSWORD_DEFAULT),
    'role' => 'superadmin', 'active' => 1,
]);
$_SERVER['HTTP_HOST'] = 'panel.example.test';
$_SESSION['active_tenant'] = $tid;
$app->auth()->login($userId);

$utcStamp = '2026-11-09 08:17:00';
$db->insert('cdr', [
    'tenant_id' => $tid, 'calldate' => $utcStamp, 'src' => '01831039102',
    'dst' => '09642105105', 'uniqueid' => 'tz-1',
    'disposition' => 'ANSWERED', 'billsec' => 24,
]);

$expected = format_cdr_date($utcStamp, 'M j, g:i A');
$raw      = format_date($utcStamp, 'M j, g:i A');
$check($expected === 'Nov 9, 2:17 PM' && $raw === 'Nov 9, 8:17 AM',
    'fixture separates tenant-local rendering from the raw stored timestamp');

$controller = new \App\Controllers\DashboardController();

$live  = $controller->liveCalls(new \App\Core\Request('GET', '/dashboard/live-calls'));
$payload = json_decode($live->body(), true);
$liveTime = (string) ($payload['recent_calls'][0]['time'] ?? '');
$check($liveTime === $expected,
    "live dashboard feed renders tenant-local time (got \"{$liveTime}\", want \"{$expected}\")");

$page = $controller->index(new \App\Core\Request('GET', '/dashboard'));
$html = (string) $page->body();
$check(str_contains($html, $expected),
    'dashboard initial payload renders tenant-local time');
$check(!str_contains($html, $raw),
    'dashboard initial payload does not render the stored UTC wall-clock time');

echo 'All dashboard timezone checks passed.' . PHP_EOL;
