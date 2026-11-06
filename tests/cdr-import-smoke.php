<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$dbPath = $base . '/storage/cache/cdr-import-smoke.sqlite';
$csvPath = $base . '/storage/cache/cdr-import-smoke.csv';
@mkdir(dirname($dbPath), 0775, true);
@unlink($dbPath);
@unlink($csvPath);
$offsetPath = $base . '/storage/cache/cdr-import-' . sha1($csvPath) . '.offset';
$reconcilePath = $base . '/storage/cache/cdr-ringgroup-reconcile-v1-' . sha1($csvPath) . '.done';
@unlink($offsetPath);
@unlink($reconcilePath);

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (require $base . '/resources/config/schema.php' as $sql) { $pdo->exec($sql); }
$pdo->exec("INSERT INTO tenants (name, context, enabled) VALUES ('Test', 'test', 1)");
file_put_contents($csvPath, implode(',', [
    '2026-11-05 10:00:00', '201', '202', 'test', 'PJSIP/test_201', 'PJSIP/test_202',
    'Dial', 'PJSIP/test_202', '30', '25', 'ANSWERED', '3', '', 'test-unique-1', '',
]) . "\n" . implode(',', [
    '2026-11-05 10:01:00', '201', '01800000000', 'test-outbound-7', 'PJSIP/test_201', 'PJSIP/trunk-7',
    'Dial', 'PJSIP/01800000000@trunk-7', '30', '25', 'ANSWERED', '3', '', 'test-unique-2', 'recording:test.wav',
]) . "\n" . implode(',', [
    '2026-11-05 10:00:08', '09611544567', '09642359359', 'test-inbound', 'PJSIP/trunk-1', 'PJSIP/201@test',
    'Dial', 'PJSIP/201@test&PJSIP/202@test', '20', '20', 'NO ANSWER', '3', '', 'ring-unique-1', 'recording:ring.wav',
]) . "\n" . implode(',', [
    '2026-11-05 10:00:16', '09611544567', '09642359359', 'test-inbound', 'PJSIP/trunk-1', 'PJSIP/202@test',
    'Dial', 'PJSIP/201@test&PJSIP/202@test', '28', '28', 'ANSWERED', '3', '', 'ring-unique-1', 'recording:ring.wav',
]) . "\n");
$pdo = null;

$run = static function () use ($base, $dbPath, $csvPath): void {
    $env = array_merge($_ENV, [
        'DB_DRIVER' => 'sqlite', 'DB_NAME' => $dbPath, 'CDR_CSV_PATH' => $csvPath,
    ]);
    $process = proc_open([PHP_BINARY, $base . '/scripts/import-cdr.php'], [
        1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes, $base, $env);
    if (!is_resource($process)) { throw new RuntimeException('Could not run CDR importer'); }
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException(trim($output)); }
};

$run();
$pdo = new PDO('sqlite:' . $dbPath);
if ((int) $pdo->query('SELECT COUNT(*) FROM cdr')->fetchColumn() !== 3) throw new RuntimeException('Initial CDR import failed');
if ((int) $pdo->query('SELECT COUNT(*) FROM cdr WHERE tenant_id = 1')->fetchColumn() !== 3) throw new RuntimeException('Route subcontext was not mapped to its tenant');
if ((int) $pdo->query("SELECT COUNT(*) FROM cdr WHERE uniqueid = 'ring-unique-1' AND disposition = 'ANSWERED' AND dstchannel = 'PJSIP/202@test'")->fetchColumn() !== 1) throw new RuntimeException('Ring Group answered member did not replace the unanswered member CDR');
if ((int) $pdo->query('SELECT COUNT(*) FROM cdr_import_log')->fetchColumn() !== 4) throw new RuntimeException('Per-row CDR import ledger was not written');
$pdo->exec("UPDATE cdr SET disposition = 'NO ANSWER', dstchannel = 'PJSIP/201@test', billsec = 20 WHERE uniqueid = 'ring-unique-1'");
$pdo->exec('DELETE FROM cdr_import_log');
$pdo->exec("INSERT INTO cdr_import_log (uniqueid, tenant_id, imported_at) VALUES
    ('test-unique-1', 1, CURRENT_TIMESTAMP), ('test-unique-2', 1, CURRENT_TIMESTAMP), ('ring-unique-1', 1, CURRENT_TIMESTAMP)");
$pdo = null;
@unlink($reconcilePath);
$run();
$pdo = new PDO('sqlite:' . $dbPath);
if ((int) $pdo->query("SELECT COUNT(*) FROM cdr WHERE uniqueid = 'ring-unique-1' AND disposition = 'ANSWERED' AND dstchannel = 'PJSIP/202@test'")->fetchColumn() !== 1) throw new RuntimeException('Legacy Ring Group history reconciliation failed');
$pdo->exec('DELETE FROM cdr');
$pdo = null;

$run();
$pdo = new PDO('sqlite:' . $dbPath);
if ((int) $pdo->query('SELECT COUNT(*) FROM cdr')->fetchColumn() !== 0) throw new RuntimeException('Deleted CDR was imported again');
$pdo = null;
@unlink($dbPath);
@unlink($csvPath);
@unlink($offsetPath);
@unlink($reconcilePath);
if (!str_contains(file_get_contents($base . '/scripts/update-helper.sh'), 'asterisk-panel-cdr.path')
    || !str_contains(file_get_contents($base . '/scripts/update-helper.sh'), 'OnUnitActiveSec=15s')) throw new RuntimeException('Near-real-time CDR importer units are missing');
echo "CDR import ledger prevents deleted history from returning.\n";
