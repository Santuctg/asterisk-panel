<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
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

$view = file_get_contents(BASE_PATH . '/themes/default/views/cdr/index.php');
$check(str_contains($view, 'name="from"') && str_contains($view, 'name="to"') && str_contains($view, 'http_build_query'),
    'Call History renders and preserves From/To filters');

echo "All CDR date filter checks passed.\n";
