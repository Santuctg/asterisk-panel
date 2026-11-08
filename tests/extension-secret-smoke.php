<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
require BASE_PATH . '/app/Core/Autoloader.php';
App\Core\Autoloader::register(BASE_PATH);

$check = static function (bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    echo 'PASS: ' . $label . PHP_EOL;
};

$validator = App\Core\Validator::make(['secret' => '1'], ['secret' => 'required']);
$check(!$validator->fails(), 'one-character SIP password is accepted');

$controller = file_get_contents(BASE_PATH . '/app/Controllers/ExtensionController.php');
$check(substr_count($controller, "'secret'    => 'required',") === 2, 'create and update have no SIP password length rule');

$view = file_get_contents(BASE_PATH . '/themes/default/views/extensions/form.php');
$check(str_contains($view, 'type="password" id="secret" name="secret" value=""')
    && str_contains($view, 'Leave blank to keep the current SIP password'),
    'extension edit form keeps the stored SIP password out of HTML');
$check(str_contains($view, 'data-secret-reveal=') && str_contains($view, 'secret-eye-button')
    && str_contains($view, "fragment('icons/eye')") && str_contains($view, 'placeholder="••••••••"'),
    'extension password field offers authenticated reveal through an inline eye button');
$check(str_contains($view, 'Outbound route') && str_contains($view, 'Internal calls only'), 'extension form defaults to internal-only calling');
$check(!str_contains($view, 'Phone registration settings'), 'extension form omits phone registration settings card');
$listView = file_get_contents(BASE_PATH . '/themes/default/views/extensions/index.php');
$check(str_contains($listView, 'Registered') && str_contains($listView, 'Unregistered'), 'extension list shows registration state');
$check(str_contains($listView, 'SIP login'), 'extension list shows username and tenant realm');
$check(str_contains($listView, 'Internal only'), 'extension list shows outbound permission');

echo "All extension SIP password checks passed.\n";
