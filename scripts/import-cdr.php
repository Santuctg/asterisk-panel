<?php

declare(strict_types=1);

/**
 * Import Asterisk CSV CDRs into the panel database (idempotent).
 *
 * Usage:
 *   php scripts/import-cdr.php
 *
 * Reads /var/log/asterisk/cdr-csv/Master.csv (override with CDR_CSV_PATH)
 * and appends any rows whose `uniqueid` has not been seen before.
 *
 * Tenant mapping: each row carries a `dcontext` (the Asterisk context). We map
 * that back to a tenant via the `tenants.context` column. If no match, the row
 * is stored with `tenant_id = NULL` and still appears in the audit of the
 * superadmin, but is hidden from per-tenant views.
 *
 * Schedule it from cron, e.g. every 5 minutes:
 *   cron: 0,5,10,15,20,25,30,35,40,45,50,55 * * * * /usr/bin/php /opt/asterisk-panel/scripts/import-cdr.php
 */

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');

require BASE_PATH . '/app/bootstrap.php';

use App\Core\App;
use App\Core\Logger;

$app = App::getInstance();
$app->boot();

$db = App::getInstance()->db();

$csvPath = getenv('CDR_CSV_PATH') ?: '/var/log/asterisk/cdr-csv/Master.csv';
$offsetDir = BASE_PATH . '/storage/cache';
$offsetPath = $offsetDir . '/cdr-import-' . sha1($csvPath) . '.offset';

if (!is_readable($csvPath)) {
    fwrite(STDERR, "CDR CSV not readable: {$csvPath}\n");
    exit(1);
}

// Longest context wins so tenants named "a" and "a-b" cannot overlap.
$tenantContexts = $db->all('SELECT id, context FROM tenants ORDER BY LENGTH(context) DESC');
$resolveTenant = static function (string $dcontext) use ($tenantContexts): ?int {
    foreach ($tenantContexts as $tenant) {
        $context = (string) $tenant['context'];
        if ($dcontext === $context || str_starts_with($dcontext, $context . '-')) {
            return (int) $tenant['id'];
        }
    }
    return null;
};

// Repair older rows imported from route-specific contexts such as
// default-outbound-1. These rows already exist in the permanent import ledger,
// so reconciliation must happen independently of new CSV inserts.
foreach ($db->all('SELECT id, uniqueid, dcontext FROM cdr WHERE tenant_id IS NULL') as $existing) {
    $tenantId = $resolveTenant((string) $existing['dcontext']);
    if ($tenantId !== null) {
        $db->update('cdr', (int) $existing['id'], ['tenant_id' => $tenantId]);
        if (!empty($existing['uniqueid'])) {
            $db->run('UPDATE cdr_import_log SET tenant_id = ? WHERE uniqueid = ?', [$tenantId, $existing['uniqueid']]);
        }
    }
}

$handle = fopen($csvPath, 'r');
if (!$handle) {
    fwrite(STDERR, "Cannot open {$csvPath}\n");
    exit(1);
}

// Detect and skip the header row if present.
$header = fgetcsv($handle, 0, ',', '"', '\\');
if ($header && in_array('uniqueid', $header, true)) {
    $cols = array_flip(array_map('strval', $header));
} else {
    // cdr_csv's stock Master.csv has 18 columns (including clid and the
    // start/answer/end timestamps). Keep compatibility with the panel's
    // earlier compact 15-column export as well.
    $stockCsv = count($header ?: []) >= 18;
    rewind($handle);
    $cols = array_flip($stockCsv
        ? [
            'accountcode','src','dst','dcontext','clid','channel','dstchannel',
            'lastapp','lastdata','calldate','answer','end','duration','billsec',
            'disposition','amaflags','uniqueid','userfield',
        ]
        : [
            'calldate','src','dst','dcontext','channel','dstchannel','lastapp',
            'lastdata','duration','billsec','disposition','amaflags','accountcode',
            'uniqueid','userfield',
        ]
    );
}

// Continue where the previous run stopped. This keeps frequent path/timer
// imports fast even when Master.csv has grown over months or years.
$offset = is_file($offsetPath) ? (int) trim((string) file_get_contents($offsetPath)) : 0;
$size = filesize($csvPath) ?: 0;
if ($offset > 0 && $offset <= $size) {
    fseek($handle, $offset);
} elseif ($offset > $size) {
    // Asterisk log rotation/truncation: begin at the start of the new file.
    rewind($handle);
    if ($header && in_array('uniqueid', $header, true)) { fgetcsv($handle, 0, ',', '"', '\\'); }
}

$inserted = 0;
$skipped  = 0;

while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    if (count($row) < 5) {
        continue;
    }

    $get = static function (string $col, $default = '') use ($row, $cols): mixed {
        $i = $cols[$col] ?? null;
        return $i !== null && isset($row[$i]) ? $row[$i] : $default;
    };

    $uniqueid = (string) $get('uniqueid');
    if ($uniqueid === '') {
        $skipped++;
        continue;
    }

    // Permanent idempotency: history may be deleted from `cdr`, but rows still
    // present in Master.csv must never come back on the next timer run.
    if ($db->scalar('SELECT COUNT(*) FROM cdr_import_log WHERE uniqueid = ?', [$uniqueid]) > 0
        || $db->scalar('SELECT COUNT(*) FROM cdr WHERE uniqueid = ?', [$uniqueid]) > 0) {
        $skipped++;
        continue;
    }

    $dcontext = (string) $get('dcontext');
    $tenantId = $resolveTenant($dcontext);

    $db->insert('cdr', [
        'tenant_id'   => $tenantId,
        'calldate'    => (string) $get('calldate'),
        'src'         => (string) $get('src'),
        'dst'         => (string) $get('dst'),
        'dcontext'    => $dcontext,
        'channel'     => (string) $get('channel'),
        'dstchannel'  => (string) $get('dstchannel'),
        'lastapp'     => (string) $get('lastapp'),
        'lastdata'    => (string) $get('lastdata'),
        'duration'    => (int) $get('duration', 0),
        'billsec'     => (int) $get('billsec', 0),
        'disposition' => (string) $get('disposition'),
        'amaflags'    => (string) $get('amaflags'),
        'accountcode' => (string) $get('accountcode'),
        'uniqueid'    => $uniqueid,
        'userfield'   => (string) $get('userfield'),
    ]);
    $db->insert('cdr_import_log', [
        'uniqueid' => $uniqueid,
        'tenant_id' => $tenantId,
        'imported_at' => date('Y-m-d H:i:s'),
    ]);

    $inserted++;
}

$nextOffset = ftell($handle);
fclose($handle);
if (!is_dir($offsetDir)) { @mkdir($offsetDir, 0775, true); }
if ($nextOffset !== false) { @file_put_contents($offsetPath, (string) $nextOffset, LOCK_EX); }

echo "CDR import complete: {$inserted} inserted, {$skipped} skipped (already present or blank).\n";
Logger::info('CDR import', ['inserted' => $inserted, 'skipped' => $skipped]);
