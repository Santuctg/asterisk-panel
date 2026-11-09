<?php

declare(strict_types=1);

// Run: php tests/role-permission-consistency-smoke.php
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/app/bootstrap.php';

use App\Core\Auth;

$check = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    echo 'PASS: ' . $message . PHP_EOL;
};

$roleView = file_get_contents(BASE_PATH . '/themes/default/views/roles/index.php');
$routes = file_get_contents(BASE_PATH . '/resources/routes.php');
$layout = file_get_contents(BASE_PATH . '/themes/default/layouts/app.php');

if (!preg_match('/\$permissionGroups\s*=\s*\[(.*?)\];/s', $roleView, $groupMatch)) {
    throw new RuntimeException('FAIL: role permission groups are readable');
}
preg_match_all("/'([a-z_]+\.[a-z_]+)'/", $groupMatch[1], $keyMatches);
$groupKeys = array_map('strval', $keyMatches[1]);
$assignable = array_keys(Auth::assignableAbilities());
$missing = array_values(array_diff($assignable, $groupKeys));
$extra = array_values(array_diff($groupKeys, $assignable));
$duplicates = array_keys(array_filter(array_count_values($groupKeys), static fn (int $count): bool => $count > 1));
$check($missing === [] && $extra === [] && $duplicates === [],
    'role groups match assignable abilities; missing=[' . implode(', ', $missing)
    . '], extra=[' . implode(', ', $extra) . '], duplicates=[' . implode(', ', $duplicates) . ']');

foreach (['/', '/dashboard', '/dashboard/live-calls', '/search'] as $path) {
    $quoted = preg_quote($path, '/');
    $check((bool) preg_match('/\$router->get\(\'' . $quoted . '\'.*Auth::require\(\'dashboard\.view\'\).*\);/', $routes),
        "{$path} requires dashboard.view");
}

$check((bool) preg_match("/'label'\s*=>\s*'Dashboard'.*?'ability'\s*=>\s*'dashboard\.view'/", $layout),
    'Dashboard navigation requires dashboard.view');

$systemChildren = [
    'Status' => 'system_status.view',
    'Settings' => 'system_settings.view',
    'SSL / HTTPS' => 'tenant.manage',
    'Audit Log' => 'audit.view',
];
foreach ($systemChildren as $label => $ability) {
    $check((bool) preg_match("/'label'\s*=>\s*'" . preg_quote($label, '/')
        . "'.*?'ability'\s*=>\s*'" . preg_quote($ability, '/') . "'/", $layout),
        "System {$label} navigation requires {$ability}");
}
$check(str_contains($layout, "array_filter(\$item['children'], \$canSee)")
    && str_contains($layout, 'if (!$visibleChildren) continue;')
    && str_contains($layout, "\$item['children'] = \$visibleChildren;")
    && str_contains($layout, "\$item['href'] = \$visibleChildren[0]['href'];"),
    'System navigation filters children, skips empty groups, and links to the first visible child');

foreach (['/web-phone', '/web-phone/connect', '/web-phone/session'] as $path) {
    $quoted = preg_quote($path, '/');
    preg_match('/\$router->(?:get|post)\(\'' . $quoted . '\'.*\);/', $routes, $routeLine);
    $check(isset($routeLine[0]) && !str_contains($routeLine[0], "Auth::require('webphone.use')"),
        "{$path} keeps standalone Web Phone authentication");
}

echo 'All role permission consistency checks passed.' . PHP_EOL;
