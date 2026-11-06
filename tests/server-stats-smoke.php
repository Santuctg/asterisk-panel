<?php

declare(strict_types=1);

// Run: php tests/server-stats-smoke.php
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
require BASE_PATH . '/app/bootstrap.php';

use App\Services\Asterisk\AsteriskManager;
use App\Services\ServerStats;

$_SESSION = [];

$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};

// ---------------------------------------------------------------------
// format_bytes / usage_level
// ---------------------------------------------------------------------
$check(format_bytes(0) === '0 B', 'zero bytes formats as 0 B');
$check(format_bytes(512) === '512 B', 'small values stay in bytes');
$check(format_bytes(1536) === '1.5 kB', 'kilobytes use one decimal');
$check(format_bytes(1024 * 1024 * 1.5) === '1.5 MB', 'megabytes format without a trailing zero');
$check(format_bytes(12 * 1024 ** 3) === '12 GB', 'gigabytes drop the decimal when whole');
$check(format_rate(2048) === '2 kB/s', 'throughput is labelled per second');

$check(usage_level(10.0) === 'ok' && usage_level(69.9) === 'ok', 'light usage reads as ok');
$check(usage_level(70.0) === 'warn' && usage_level(84.9) === 'warn', 'medium usage reads as warn');
$check(usage_level(85.0) === 'hot' && usage_level(100.0) === 'hot', 'heavy usage reads as hot');

// ---------------------------------------------------------------------
// CPU: /proc/stat parsing and the two-sample percentage
// ---------------------------------------------------------------------
$cpuBefore = ServerStats::parseCpuLine("cpu  1000 0 1000 8000 0 0 0 0 0 0");
$cpuAfter  = ServerStats::parseCpuLine("cpu  2000 0 2000 12000 0 0 0 0 0 0");
$check($cpuBefore === ['total' => 10000, 'idle' => 8000] && $cpuAfter !== null,
    'aggregate cpu line parses into total and idle ticks');
$check(ServerStats::cpuPercent($cpuBefore, $cpuAfter) === 33.3,
    'cpu percentage uses the delta between two samples');

$iowait = ServerStats::parseCpuLine("cpu0 100 0 100 400 300 0 0 0 0 0");
$check($iowait !== null && $iowait['idle'] === 700, 'iowait counts as idle time');
$check(ServerStats::parseCpuLine('cpu  not numbers here') === null, 'a malformed cpu line is rejected');
$check(ServerStats::parseCpuLine('') === null, 'an empty cpu line is rejected');
$check(ServerStats::parseCpuLine("procs_running 2") === null, 'non-cpu /proc/stat rows are rejected');
$check(ServerStats::cpuPercent(['total' => 10, 'idle' => 5], ['total' => 10, 'idle' => 5]) === null,
    'a zero-length cpu window is rejected instead of dividing by zero');

// ---------------------------------------------------------------------
// Memory: /proc/meminfo
// ---------------------------------------------------------------------
$mem = ServerStats::parseMeminfo(
    "MemTotal:       16384000 kB\n" .
    "MemFree:         1000000 kB\n" .
    "MemAvailable:    8192000 kB\n" .
    "Buffers:          200000 kB\n"
);
$check($mem !== null && $mem['total'] === 16384000 * 1024, 'memtotal converts kB to bytes');
$check($mem !== null && $mem['used'] === 8192000 * 1024 && $mem['percent'] === 50.0,
    'used memory is total minus available');
$check(ServerStats::parseMeminfo("MemFree: 1000 kB\n") === null,
    'meminfo without MemTotal/MemAvailable degrades to null');
$check(ServerStats::parseMeminfo('') === null, 'empty meminfo degrades to null');

// ---------------------------------------------------------------------
// Disk
// ---------------------------------------------------------------------
$disk = ServerStats::diskStats(100 * 1024 ** 3, 40 * 1024 ** 3);
$check($disk !== null && $disk['used'] === 60 * 1024 ** 3 && $disk['percent'] === 60.0,
    'disk usage reports used bytes and percentage');
$check(ServerStats::diskStats(0, 0) === null, 'a zero-size disk degrades to null');
$check(ServerStats::diskStats(100, -5) === null, 'a negative free space degrades to null');
$check(ServerStats::diskStats(100, 500)['free'] === 100, 'free space larger than the total is clamped');

// ---------------------------------------------------------------------
// Network: /proc/net/dev
// ---------------------------------------------------------------------
$netBefore = ServerStats::parseNetDev(
    "Inter-|   Receive                                                |  Transmit\n" .
    " face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed\n" .
    "    lo: 99999    10    0    0    0     0          0         0   99999    10    0    0    0     0       0          0\n" .
    "  eth0:  5000    10    0    0    0     0          0         0    2500     5    0    0    0     0       0          0\n" .
    "  eth1:   700     3    0    0    0     0          0         0     300     2    0    0    0     0       0          0\n"
);
$check($netBefore === ['rx' => 5700, 'tx' => 2800],
    'network counters sum every interface and skip loopback');
$check(ServerStats::parseNetDev("Inter-| Receive\n face |bytes\n") === null,
    'header-only netdev text degrades to null');
$check(ServerStats::parseNetDev('') === null, 'empty netdev text degrades to null');

$rates = ServerStats::netRates($netBefore, ['rx' => 6700, 'tx' => 3200], 2.0);
$check($rates === ['rx' => 500.0, 'tx' => 200.0], 'network rate divides the delta by the window');
$check(ServerStats::netRates($netBefore, ['rx' => 100, 'tx' => 100], 2.0) === null,
    'a counter reset mid-window degrades to null');
$check(ServerStats::netRates($netBefore, ['rx' => 6700, 'tx' => 3200], 0.0) === null,
    'a zero-length network window is rejected instead of dividing by zero');

// ---------------------------------------------------------------------
// contactIp: where each registered phone actually lives
// ---------------------------------------------------------------------
$ipCases = [
    '1001@tenant-a.example.test/sip:1001@192.0.2.10'        => '192.0.2.10',
    '1001@tenant-a.example.test/sip:1001@192.0.2.11:5062'   => '192.0.2.11',
    'trunk-1-aor/sip:198.51.100.10'                         => '198.51.100.10',
    'web/sips:201@[2001:db8::1]:5061'                       => '2001:db8::1',
    '1001@tenant.test/sip:1001@phone.example.test:5060'     => '',
    '1001@tenant.test/sip:1001'                             => '',
    ''                                                      => '',
];
foreach ($ipCases as $uri => $expected) {
    $check(AsteriskManager::contactIp($uri) === $expected,
        'contactIp(' . ($uri === '' ? '""' : $uri) . ') => ' . ($expected === '' ? '""' : $expected));
}

// ---------------------------------------------------------------------
// parseContactDetails now carries the registered IP list
// ---------------------------------------------------------------------
$details = AsteriskManager::parseContactDetails(
    "Contact: 1001@tenant-a.example.test/sip:1001@192.0.2.10 abc123 Avail 12.000\n" .
    "Contact: 1001@tenant-a.example.test/sip:1001@192.0.2.11 def456 Avail 14.000\n" .
    "Contact: 1001@tenant-a.example.test/sip:1001@192.0.2.11 ghi789 Avail 15.000\n" .
    "Contact: trunk-1-aor/sip:198.51.100.10 jkl012 NonQual nan\n"
);
$check($details['1001@tenant-a.example.test']['count'] === 3
    && $details['1001@tenant-a.example.test']['ips'] === ['192.0.2.10', '192.0.2.11'],
    'multiple contacts collect each distinct IP once');
$check($details['trunk-1-aor']['ips'] === ['198.51.100.10']
    && $details['trunk-1-aor']['status'] === 'NonQual',
    'a trunk contact exposes its IP alongside the status');
$check(!isset($details['1001@tenant-a.example.test']['ips'][2]),
    'duplicate registrations do not repeat the same IP');

// ---------------------------------------------------------------------
// The live sampler never throws, whatever platform it runs on.
// ---------------------------------------------------------------------
$stats = (new ServerStats())->sample(0.01);
$check(array_keys($stats) === ['cpu', 'memory', 'disk', 'network'],
    'sample() returns the four resource groups');
foreach (['cpu', 'memory', 'disk', 'network'] as $group) {
    $ok = $stats[$group] === null
        || (is_array($stats[$group]) && $stats[$group] !== []);
    $check($ok, "sample() {$group} is null or populated, never malformed");
}
if ($stats['memory'] !== null) {
    $check($stats['memory']['percent'] >= 0 && $stats['memory']['percent'] <= 100,
        'live memory percentage stays within 0-100');
}
if ($stats['cpu'] !== null) {
    $check($stats['cpu'] >= 0 && $stats['cpu'] <= 100, 'live CPU percentage stays within 0-100');
}

// ---------------------------------------------------------------------
// The status view renders the Server resources card without failing.
// ---------------------------------------------------------------------
$renderStatus = static function (array $data): string {
    $data['__layout'] = '';
    return App\Core\View::getInstance()->render('system/status', $data);
};

$base = [
    'online'      => true,
    'version'     => ['version' => '20.5.0', 'raw' => 'Asterisk 20.5.0'],
    'uptime'      => '3 days, 4:05:06',
    'channels'    => [],
    'contacts'    => [],
    'conferences' => [],
    'queues'      => [],
    'configDir'   => '/etc/asterisk/asterisk_panel',
    'extIps'      => [],
];

$full = $renderStatus($base + ['serverStats' => [
    'cpu'     => 12.3,
    'memory'  => ['total' => 4 * 1024 ** 3, 'used' => 1536 * 1024 ** 2, 'available' => 2560 * 1024 ** 2, 'percent' => 37.5],
    'disk'    => ['total' => 30 * 1024 ** 3, 'free' => 18 * 1024 ** 3, 'used' => 12 * 1024 ** 3, 'percent' => 40.0],
    'network' => ['rx' => 1280.0, 'tx' => 340.0],
]]);
$check(str_contains($full, 'Server resources'), 'status page renders the Server resources card');
$check(str_contains($full, '12.3%') && str_contains($full, 'CPU usage'), 'CPU tile renders its value');
$check(str_contains($full, '1.5 GB') && str_contains($full, '37.5% of 4 GB'), 'memory tile renders used and total');
$check(str_contains($full, '12 GB') && str_contains($full, '40.0% of 30 GB'), 'disk tile renders used and total');
$check(str_contains($full, '1.3 kB/s') && str_contains($full, 'Sending 340 B/s'), 'network tile renders both directions');
$check(str_contains($full, 'class="meter"') && str_contains($full, 'meter"><span class="ok"'),
    'usage meters render with a threshold colour');
$check(!str_contains($full, 'Something went wrong'), 'populated status page never falls through to the 500 page');

// Every value null (the Windows / restricted-host case) must still render.
$none = $renderStatus($base + ['serverStats' => [
    'cpu' => null, 'memory' => null, 'disk' => null, 'network' => null,
]]);
$check(str_contains($none, 'CPU not available') && str_contains($none, 'Network not available'),
    'unavailable resources are labelled instead of erroring');
$check(str_contains($none, 'Server resources') && !str_contains($none, 'Something went wrong'),
    'null resource stats still render a complete status page');

// No serverStats key at all (legacy caller) must not fatal.
$legacy = $renderStatus($base);
$check(str_contains($legacy, 'Server resources') && !str_contains($legacy, 'Something went wrong'),
    'a status page rendered without serverStats still works');

// The four new icons must exist on disk (the shared icon scan only catches
// fragments that appear in a view, so assert them here too).
foreach (['cpu', 'memory', 'disk', 'network'] as $icon) {
    $check(is_file(BASE_PATH . "/themes/default/views/icons/{$icon}.php"), "icons/{$icon}.php exists");
}

echo "All server stats smoke checks passed.\n";
