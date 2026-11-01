<?php

declare(strict_types=1);

/** Generate all panel-managed Asterisk configuration from the current DB. */

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');

require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();

$generator = new App\Services\Asterisk\ConfigGenerator();
$files = $generator->generateAll();

echo 'Generated ' . count($files) . ' files in ' . $generator->dir() . PHP_EOL;
foreach ($files as $file) {
    echo '  - ' . $file . PHP_EOL;
}
