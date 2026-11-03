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
$check(str_contains($view, 'Any non-empty password is accepted.'), 'extension form explains unrestricted length');

echo "All extension SIP password checks passed.\n";
