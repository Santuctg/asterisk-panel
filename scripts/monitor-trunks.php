<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$result = (new App\Services\Asterisk\TrunkStatusTracker())->sample();
echo $result['available']
    ? 'Recorded ' . count($result['statuses']) . " trunk status(es).\n"
    : "Asterisk unavailable; trunk history left unchanged.\n";
