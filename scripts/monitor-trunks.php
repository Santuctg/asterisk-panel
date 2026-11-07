<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();

$trunks = (new App\Services\Asterisk\TrunkStatusTracker())->sample();
$extensions = (new App\Services\Asterisk\ExtensionStatusTracker())->sample();

// The status page graph needs samples even when nobody has it open, so the
// once-a-minute systemd timer also records host CPU/memory/disk/network.
$recordedServerSample = false;
try {
    $history = new App\Services\ServerStatsHistory();
    $recordedServerSample = $history->record((new App\Services\ServerStats())->sample());
    $history->prune();
} catch (Throwable $e) {
    App\Core\Logger::info('Server stats history unavailable', ['error' => $e->getMessage()]);
}

$parts = [];
if ($trunks['available']) {
    $parts[] = count($trunks['statuses']) . ' trunk status(es)';
}
if ($extensions['available']) {
    $parts[] = count($extensions['statuses']) . ' extension status(es)';
}
if ($recordedServerSample) {
    $parts[] = '1 server sample';
}

echo $parts
    ? 'Recorded ' . implode(' and ', $parts) . ".\n"
    : "Asterisk unavailable; status history left unchanged.\n";
