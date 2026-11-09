<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
};

$runInstaller = static function (string $context) use ($base): array {
    $dbPath = $base . '/storage/cache/install-context-smoke.sqlite';
    if (is_file($dbPath)) {
        @unlink($dbPath);
    }
    $command = [
        PHP_BINARY,
        $base . '/scripts/install.php',
        '--username=admin',
        '--password=test-password',
        '--email=admin@example.test',
        '--tenant=Context Smoke',
        '--context=' . $context,
    ];
    $env = array_merge(getenv(), [
        'DB_DRIVER' => 'sqlite',
        'DB_NAME' => $dbPath,
        'APP_URL' => 'http://panel.example.test',
        'APP_PRIMARY_HOST' => 'panel.example.test',
    ]);
    $process = proc_open($command, [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $base, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('FAIL: could not start the installer');
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $tenants = [];
    if (is_file($dbPath)) {
        $pdo = new PDO('sqlite:' . $dbPath);
        $tenants = $pdo->query('SELECT context FROM tenants')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $pdo = null;
        @unlink($dbPath);
    }
    return [$status, $stdout . $stderr, $tenants];
};

[$badStatus, $badOutput, $badTenants] = $runInstaller("bad ctx;rm -rf /tmp/x");
$check($badStatus !== 0 && !in_array('bad ctx;rm -rf /tmp/x', $badTenants, true)
    && stripos($badOutput, 'context') !== false,
    'installer rejects unsafe tenant contexts before creating them');

[$goodStatus, $goodOutput, $goodTenants] = $runInstaller('acme-west_2');
$check($goodStatus === 0 && in_array('acme-west_2', $goodTenants, true),
    'installer still accepts well-formed tenant contexts');

echo 'All installer tenant-context checks passed.' . PHP_EOL;
