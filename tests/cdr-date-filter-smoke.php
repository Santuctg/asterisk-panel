<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('ASTERISK_CDR_TIMEZONE=UTC');
require BASE_PATH . '/app/bootstrap.php';

$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo "PASS: {$label}\n";
};

$tenant = new DateTimeZone('America/New_York');
$cdr = new DateTimeZone('UTC');
$range = App\Controllers\CdrController::dateRange('2026-03-08', '2026-03-08', $tenant, $cdr);
$check($range === ['from' => '2026-03-08', 'to' => '2026-03-08', 'start' => '2026-03-08 05:00:00', 'end' => '2026-03-09 04:00:00'],
    'date range uses tenant-local inclusive boundaries across DST');
$reversed = App\Controllers\CdrController::dateRange('2026-04-10', '2026-04-01', $tenant, $cdr);
$check($reversed['from'] === '2026-04-01' && $reversed['to'] === '2026-04-10', 'reversed dates normalize chronologically');
$invalid = App\Controllers\CdrController::dateRange('not-a-date', '2026-04-01', $tenant, $cdr);
$check($invalid['from'] === '' && $invalid['to'] === '2026-04-01' && $invalid['start'] === null && $invalid['end'] !== null,
    'invalid dates are ignored while one-sided ranges remain usable');

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) $db->exec($sql);
$tenantId = $db->insert('tenants', [
    'name' => 'CDR Tenant', 'context' => 'cdr_tenant', 'timezone' => 'America/New_York', 'enabled' => 1,
]);
$foreignTenantId = $db->insert('tenants', [
    'name' => 'Foreign CDR Tenant', 'context' => 'foreign_cdr', 'timezone' => 'UTC', 'enabled' => 1,
]);
$adminId = $db->insert('users', [
    'tenant_id' => null, 'username' => 'cdr-admin', 'password' => password_hash('test-only', PASSWORD_DEFAULT),
    'role' => 'superadmin', 'active' => 1,
]);
$app->auth()->login($adminId);
$_SESSION['active_tenant'] = $tenantId;
$insertCdr = static function (int $tenant, string $time, string $src, string $disposition, string $uniqueId) use ($db): void {
    $db->insert('cdr', [
        'tenant_id' => $tenant, 'calldate' => $time, 'src' => $src, 'dst' => '200',
        'dcontext' => 'internal', 'disposition' => $disposition, 'billsec' => 10, 'uniqueid' => $uniqueId,
    ]);
};
$insertCdr($tenantId, '2026-03-08 04:59:59', 'needle', 'ANSWERED', 'before-local-day');
foreach (range(1, 6) as $i) {
    $insertCdr($tenantId, '2026-03-08 ' . sprintf('%02d', 5 + $i) . ':00:00', 'needle-' . $i, 'ANSWERED', 'matching-' . $i);
}
$insertCdr($tenantId, '2026-03-09 04:00:00', 'needle', 'ANSWERED', 'exclusive-end');
$insertCdr($tenantId, '2026-03-08 12:00:00', 'needle', 'BUSY', 'wrong-disposition');
$insertCdr($foreignTenantId, '2026-03-08 12:00:00', 'needle', 'ANSWERED', 'foreign-row');

$cdrHtml = (new App\Controllers\CdrController())->index(new App\Core\Request('GET', '/cdr', [
    'q' => 'needle', 'disposition' => 'ANSWERED', 'from' => '2026-03-08', 'to' => '2026-03-08',
    'page' => '2', 'per' => '5',
]))->body();
$check(str_contains($cdrHtml, '6 record(s)') && str_contains($cdrHtml, 'needle-1')
    && !str_contains($cdrHtml, 'before-local-day') && !str_contains($cdrHtml, 'exclusive-end')
    && !str_contains($cdrHtml, 'wrong-disposition') && !str_contains($cdrHtml, 'foreign-row'),
    'controller composes tenant, search, disposition, and inclusive local-date filters');
$check(str_contains($cdrHtml, 'name="per" value="5"')
    && str_contains($cdrHtml, 'from=2026-03-08&amp;to=2026-03-08&amp;per=5&amp;page=1'),
    'CDR filter submission and pagination preserve normalized dates and per-page state');

$view = file_get_contents(BASE_PATH . '/themes/default/views/cdr/index.php');
$check(str_contains($view, 'name="from"') && str_contains($view, 'name="to"') && str_contains($view, 'http_build_query'),
    'Call History renders and preserves From/To filters');

echo "All CDR date filter checks passed.\n";
