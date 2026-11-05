<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$dbPath = $base . '/storage/cache/cdr-import-smoke.sqlite';
$csvPath = $base . '/storage/cache/cdr-import-smoke.csv';
@mkdir(dirname($dbPath), 0775, true);
@unlink($dbPath);
@unlink($csvPath);

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (require $base . '/resources/config/schema.php' as $sql) { $pdo->exec($sql); }
$pdo->exec("INSERT INTO tenants (name, context, enabled) VALUES ('Test', 'test', 1)");
file_put_contents($csvPath, implode(',', [
    '2026-11-05 10:00:00', '201', '202', 'test', 'PJSIP/test_201', 'PJSIP/test_202',
    'Dial', 'PJSIP/test_202', '30', '25', 'ANSWERED', '3', '', 'test-unique-1', '',
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
if ((int) $pdo->query('SELECT COUNT(*) FROM cdr')->fetchColumn() !== 1) throw new RuntimeException('Initial CDR import failed');
if ((int) $pdo->query('SELECT COUNT(*) FROM cdr_import_log')->fetchColumn() !== 1) throw new RuntimeException('CDR import ledger was not written');
$pdo->exec('DELETE FROM cdr');
$pdo = null;

$run();
$pdo = new PDO('sqlite:' . $dbPath);
if ((int) $pdo->query('SELECT COUNT(*) FROM cdr')->fetchColumn() !== 0) throw new RuntimeException('Deleted CDR was imported again');
$pdo = null;
@unlink($dbPath);
@unlink($csvPath);
echo "CDR import ledger prevents deleted history from returning.\n";
