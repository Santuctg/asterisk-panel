<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();

$trunks = (new App\Services\Asterisk\TrunkStatusTracker())->sample();
$extensions = (new App\Services\Asterisk\ExtensionStatusTracker())->sample();

$parts = [];
if ($trunks['available']) {
    $parts[] = count($trunks['statuses']) . ' trunk status(es)';
}
if ($extensions['available']) {
    $parts[] = count($extensions['statuses']) . ' extension status(es)';
}

echo $parts
    ? 'Recorded ' . implode(' and ', $parts) . ".\n"
    : "Asterisk unavailable; status history left unchanged.\n";
