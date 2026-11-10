<?php

declare(strict_types=1);

// Run: php tests/server-stats-smoke.php (uses only an in-memory SQLite DB).
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('AMI_HOST=127.0.0.1');
putenv('AMI_PORT=1');
putenv('APP_URL=https://pbx.example.test');
require BASE_PATH . '/app/bootstrap.php';

use App\Services\Asterisk\AsteriskManager;
use App\Services\ServerStats;
use App\Services\ServerStatsHistory;

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) {
    $db->exec($sql);
}

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

// /proc/stat is a whole file, not one line — this is what production sends.
$procStat = "cpu  1000 0 1000 8000 0 0 0 0 0 0\ncpu0 500 0 500 4000 0 0 0 0 0 0\n"
    . "cpu1 500 0 500 4000 0 0 0 0 0 0\nintr 12345\nctxt 6789\nbtime 1700000000\n"
    . "processes 100\nprocs_running 2\nprocs_blocked 0\n";
$check(ServerStats::parseCpuLine($procStat) === ['total' => 10000, 'idle' => 8000],
    'the aggregate cpu row is parsed out of the full multi-row /proc/stat file');
$check(ServerStats::parseCpuLine("cpu0 500 0 500 4000 0 0 0 0 0 0\ncpu1 500 0 500 4000 0 0 0 0 0 0\n") !== null,
    'a per-core row still parses when no aggregate row is present');
$check(ServerStats::parseCpuLine(
    "cpu0 500 0 500 4000 0 0 0 0 0 0\ncpu  1000 0 1000 8000 0 0 0 0 0 0\n"
) === ['total' => 10000, 'idle' => 8000],
    'the aggregate cpu row wins even when a per-core row comes first');

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
// 24-hour history: record, series, prune.
// ---------------------------------------------------------------------
$check((int) $db->scalar(
    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'server_stats_history'"
) === 1, 'server_stats_history table exists in the schema');

$history = new ServerStatsHistory();
$fullSample = [
    'cpu'     => 22.5,
    'memory'  => ['total' => 4 * 1024 ** 3, 'used' => 1 * 1024 ** 3, 'available' => 3 * 1024 ** 3, 'percent' => 25.0],
    'disk'    => ['total' => 40 * 1024 ** 3, 'used' => 10 * 1024 ** 3, 'free' => 30 * 1024 ** 3, 'percent' => 25.0],
    'network' => ['rx' => 1500.0, 'tx' => 900.0],
];

// Backdate a minute of samples so the graph has something to draw.
$baseTs = time() - 3600;
for ($i = 0; $i < 59; $i++) {
    $db->insert('server_stats_history', [
        'sampled_at'  => date('Y-m-d H:i:s', $baseTs + $i * 60),
        'cpu_percent' => 10 + ($i % 30),
        'mem_used'    => 512 * 1024 * 1024,
        'mem_total'   => 4 * 1024 * 1024 * 1024,
        'disk_used'   => 10 * 1024 ** 3,
        'disk_total'  => 40 * 1024 ** 3,
        'net_rx'      => 100.0,
        'net_tx'      => 50.0,
    ]);
}

$series = $history->series(24, 24);
$check($series !== [] && count($series) <= 24, 'series() buckets a day of samples into a bounded list');
$check($series !== [] && abs($series[count($series) - 1]['t'] - ($baseTs + 58 * 60)) <= 60,
    'series() timestamps follow the recorded samples');
$check($series !== [] && $series[0]['mem'] === 12.5 && $series[0]['disk'] === 25.0,
    'series() converts stored byte counters into used percentages');
$check($series !== [] && is_float($series[0]['cpu']), 'series() averages CPU per bucket');

$check($history->rows(24) !== [] && $history->rows(0) === [], 'rows() honours the look-back window');

$check($history->record($fullSample) === true, 'record() stores the first sample');
$check($history->record($fullSample) === false, 'record() skips a sample taken within the throttle window');
$check((int) $db->scalar(
    "SELECT COUNT(*) FROM server_stats_history WHERE cpu_percent = ?", [22.5]
) === 1, 'record() wrote exactly one row');

$originalTimezone = date_default_timezone_get();
date_default_timezone_set('Asia/Dhaka');
$utcProbe = $fullSample;
$utcProbe['cpu'] = 33.3;
$history->record($utcProbe, 0);
$storedUtc = (string) $db->scalar(
    'SELECT sampled_at FROM server_stats_history WHERE cpu_percent = ? ORDER BY id DESC LIMIT 1',
    [33.3]
);
$parsedUtc = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $storedUtc, new \DateTimeZone('UTC'));
$check($parsedUtc !== false && abs($parsedUtc->getTimestamp() - time()) <= 2,
    'record() stores UTC independently of the active PHP timezone');
$db->run('DELETE FROM server_stats_history WHERE cpu_percent = ?', [33.3]);
date_default_timezone_set($originalTimezone);

$db->run('DELETE FROM server_stats_history WHERE cpu_percent = ?', [22.5]);
$db->insert('server_stats_history', [
    'sampled_at' => gmdate('Y-m-d H:i:s', time() + 6 * 3600),
    'cpu_percent' => 44.4,
]);
$legacyProbe = $fullSample;
$legacyProbe['cpu'] = 55.5;
$check($history->record($legacyProbe) === true,
    'a legacy future timestamp cannot block a current UTC sample');
$check(count(array_filter(
    $history->rows(24),
    static fn (array $row): bool => (float) ($row['cpu_percent'] ?? 0) === 44.4
)) === 0, 'future legacy samples stay hidden until their timestamp is reached');
$db->run('DELETE FROM server_stats_history WHERE cpu_percent IN (?, ?)', [44.4, 55.5]);

$db->insert('server_stats_history', [
    'sampled_at' => date('Y-m-d H:i:s', time() - 3 * 86400),
    'cpu_percent' => 5.0,
]);
$pruned = $history->prune(48);
$check($pruned >= 1 && (int) $db->scalar(
    "SELECT COUNT(*) FROM server_stats_history WHERE sampled_at < ?", [date('Y-m-d H:i:s', time() - 48 * 3600)]
) === 0, 'prune() drops everything older than the retention window');

// ---------------------------------------------------------------------
// The SVG graph helper.
// ---------------------------------------------------------------------
$chartSeries = [
    ['t' => $baseTs, 'cpu' => 12.0, 'mem' => 40.0, 'disk' => 75.0],
    ['t' => $baseTs + 1800, 'cpu' => 55.0, 'mem' => 41.5, 'disk' => 75.0],
    ['t' => $baseTs + 3600, 'cpu' => 33.0, 'mem' => 42.0, 'disk' => 75.5],
];
$chart = server_resource_chart($chartSeries);
$check(str_starts_with($chart, '<svg class="chart"'), 'the graph renders an SVG for a usable series');
$check(substr_count($chart, '<path ') === 3, 'the graph plots one path per metric');
$check(str_contains($chart, 'stroke="#3b66f5"') && str_contains($chart, 'stroke="#7c5cff"')
    && str_contains($chart, 'stroke="#d97706"'), 'CPU, memory and disk use distinct colours');
$check(str_contains($chart, 'now') && str_contains($chart, '-1h'), 'the time axis runs from an hour ago to now');
$check(server_resource_chart([]) === '', 'an empty series renders no graph');
$check(server_resource_chart([['t' => $baseTs, 'cpu' => 10.0]]) === '', 'a single sample renders no graph');
$check(server_resource_chart([
    ['t' => $baseTs, 'cpu' => null, 'mem' => null, 'disk' => null],
    ['t' => $baseTs + 60, 'cpu' => null, 'mem' => null, 'disk' => null],
]) === '', 'a series with no usable values renders no graph');
$check(str_contains(server_resource_chart([
    ['t' => $baseTs, 'cpu' => 10.0, 'mem' => null, 'disk' => null],
    ['t' => $baseTs + 60, 'cpu' => 20.0, 'mem' => null, 'disk' => null],
]), '<path '), 'a series with only one usable metric still renders');
$continuousChart = server_resource_chart([
    ['t' => $baseTs, 'cpu' => 10.0],
    ['t' => $baseTs + App\Services\ServerStatsHistory::GAP_SECONDS, 'cpu' => 20.0],
]);
$gappedChart = server_resource_chart([
    ['t' => $baseTs, 'cpu' => 10.0],
    ['t' => $baseTs + 60, 'cpu' => 11.0],
    ['t' => $baseTs + App\Services\ServerStatsHistory::GAP_SECONDS + 61, 'cpu' => 20.0],
    ['t' => $baseTs + App\Services\ServerStatsHistory::GAP_SECONDS + 121, 'cpu' => 21.0],
]);
$check(substr_count($continuousChart, 'M') === 1,
    'an interval exactly at the history gap threshold remains connected');
$check(substr_count($gappedChart, 'M') === 2,
    'the server-rendered resource line breaks across a missing interval');

// ---------------------------------------------------------------------
// The realtime endpoint the status page polls.
// ---------------------------------------------------------------------
$statsAdmin = $db->insert('users', [
    'tenant_id' => null, 'username' => 'stats-root', 'password' => password_hash('test-only', PASSWORD_DEFAULT),
    'role' => 'superadmin', 'active' => 1,
]);
$app->auth()->login($statsAdmin);
$endpoint = (new App\Controllers\SystemController())->serverStats(
    new App\Core\Request('GET', '/system/server-stats')
);
$check($endpoint->status() === 200, 'the resource endpoint answers 200');
$payload = json_decode($endpoint->body(), true);
$check(is_array($payload) && array_keys($payload) === ['current', 'series', 'generated_at'],
    'the resource endpoint returns current stats, the series and a timestamp');
$check(is_array($payload['current'] ?? null)
    && array_keys($payload['current']) === ['cpu', 'memory', 'disk', 'network'],
    'the endpoint reports all four resource groups');
$check(is_array($payload['series'] ?? null) && $payload['series'] !== [],
    'the endpoint includes the 24-hour series');

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
$check(str_contains($full, 'class="meter"') && str_contains($full, 'id="srvCpuBar" class="ok"'),
    'usage meters render with a threshold colour');
$check(str_contains($full, 'id="srvChart"') && str_contains($full, 'Resource history'),
    'status page renders the 24-hour resource history card');
$check(str_contains($full, 'chart-tooltip') && str_contains($full, 'bindChartTooltip')
    && str_contains($full, "toLocaleString()"),
    'resource history hover shows the sample time and metric values');
$check(str_contains($full, 'GAP_SECONDS = 1200')
    && str_contains($full, 'function isMissingSampleTime')
    && str_contains($full, 'No sample data'),
    'polled resource charts preserve gaps and label missing hover intervals');
$check(str_contains($full, '/system/server-stats') && str_contains($full, 'INTERVAL = 10000')
    && str_contains($full, 'setInterval(poll'),
    'status page wires up the realtime resource poller');
$check(!str_contains($full, 'Something went wrong'), 'populated status page never falls through to the 500 page');
$mappedIps = App\Controllers\SystemController::extensionContactIps(
    [['extension' => '1001']],
    ['1001@default' => ['ips' => ['192.0.2.44']]],
    '',
    'default'
);
$check(($mappedIps['1001'][0] ?? '') === '192.0.2.44',
    'default tenant connected extension IP comes from its contact AOR');
$legacyMappedIps = App\Controllers\SystemController::extensionContactIps(
    [['extension' => '1001']],
    ['default_1001-web' => ['ips' => ['192.0.2.45']]],
    '',
    'default'
);
$check(($legacyMappedIps['1001'][0] ?? '') === '192.0.2.45',
    'tenant extension IP matching accepts the legacy Web Phone AOR');

$monitor = file_get_contents(BASE_PATH . '/scripts/monitor-trunks.php');
$installer = file_get_contents(BASE_PATH . '/scripts/install-server-ubuntu.sh');
$updater = file_get_contents(BASE_PATH . '/scripts/update-helper.sh');
$check(str_contains($monitor, 'ServerStatsHistory') && str_contains($monitor, 'ServerStats())->sample()'),
    'background monitor records resource history without a browser session');
$check(str_contains($installer, 'asterisk-panel-trunk-monitor.timer')
    && str_contains($installer, 'enable --now asterisk-panel-trunk-monitor.timer')
    && str_contains($updater, 'restart asterisk-panel-trunk-monitor.timer'),
    'installer and updater keep the once-a-minute background sampler active');

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
