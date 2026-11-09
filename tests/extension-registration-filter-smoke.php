<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
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

$dashboard = file_get_contents(BASE_PATH . '/themes/default/views/dashboard/index.php');
$extensions = file_get_contents(BASE_PATH . '/themes/default/views/extensions/index.php');
$check(str_contains($dashboard, "'Registered phones', '/extensions?registration=registered'"),
    'Dashboard Registered phones opens the registered extension filter');
$check(str_contains($extensions, 'name="registration"') && str_contains($extensions, 'Unregistered')
    && str_contains($extensions, 'http_build_query'), 'Extensions UI renders and preserves registration filters');

echo "All extension registration filter checks passed.\n";
