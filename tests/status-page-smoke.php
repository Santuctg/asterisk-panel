<?php

declare(strict_types=1);

// Run: php tests/status-page-smoke.php
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
require BASE_PATH . '/app/bootstrap.php';

$_SESSION = [];

$check = static function (bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    echo 'PASS: ' . $label . PHP_EOL;
};

/** Render the status view exactly as the controller does, without the layout shell. */
$renderStatus = static function (array $data): string {
    $data['__layout'] = '';
    return App\Core\View::getInstance()->render('system/status', $data);
};

/** Server-rendered contents of the chart host only (the poller script has the same fallback text). */
$chartHost = static function (string $html): string {
    $at = strpos($html, 'id="srvChart"');
    if ($at === false) {
        return '';
    }
    $start = strpos($html, '>', $at);
    $end = strpos($html, '</div>', $start);
    return $end === false ? '' : substr($html, $start, $end - $start);
};

// 1) Asterisk offline: every variable must still exist.
$offline = $renderStatus([
    'online'      => false,
    'version'     => ['version' => 'unavailable', 'raw' => ''],
    'uptime'      => '',
    'channels'    => [],
    'contacts'    => [],
    'conferences' => [],
    'queues'      => [],
    'configDir'   => '/etc/asterisk/asterisk_panel',
    'extIps'      => [],
]);

$check(str_contains($offline, 'System Status'), 'offline status page renders its heading');
$check(str_contains($offline, 'Offline') && str_contains($offline, 'Could not reach the Asterisk Manager Interface'),
    'offline status page explains the AMI outage instead of failing');
$check(!str_contains($offline, 'Something went wrong'), 'offline status page never falls through to the 500 page');

// 2) Asterisk online with live tenant data.
$online = $renderStatus([
    'online'      => true,
    'version'     => ['version' => '20.5.0', 'raw' => 'Asterisk 20.5.0'],
    'uptime'      => '3 days, 4:05:06',
    'channels'    => [['name' => 'PJSIP/201-00000001', 'context' => 'from-internal', 'app' => 'Dial', 'duration' => '00:01:12']],
    'contacts'    => [['uri' => 'sip:201@10.0.0.5:5060', 'status' => 'Registered', 'agent' => 'Grandstream GXP']],
    'conferences' => [],
    'queues'      => [['name' => '600', 'members' => [['member' => 'PJSIP/201', 'state' => 'inuse']]]],
    'configDir'   => '/etc/asterisk/asterisk_panel',
    'extIps'      => ['201' => '10.0.0.5'],
]);

$check(str_contains($online, 'Online'), 'online status page reports the connection state');
$check(!str_contains($online, 'Connected extensions'), 'redundant connected extensions stat card is removed');
$check(str_contains($online, 'PJSIP/201-00000001'), 'live channel rows render');
$check(str_contains($online, 'sip:201@10.0.0.5:5060') && str_contains($online, 'Grandstream GXP'),
    'registered endpoint rows render');
$check(str_contains($online, '<strong class="mono">600</strong>') && str_contains($online, 'PJSIP/201'),
    'queue status rows render');
$check(str_contains($online, '3 days, 4:05:06'), 'uptime renders');
$check(!str_contains($online, 'Something went wrong'), 'online status page never falls through to the 500 page');

// 3) Online with nothing registered / no tenant data.
$empty = $renderStatus([
    'online'      => true,
    'version'     => ['version' => '20.5.0', 'raw' => 'Asterisk 20.5.0'],
    'uptime'      => '',
    'channels'    => [],
    'contacts'    => [],
    'conferences' => [],
    'queues'      => [],
    'configDir'   => '/etc/asterisk/asterisk_panel',
    'extIps'      => [],
]);

$check(!str_contains($empty, 'Connected extensions'), 'empty online status omits connected extensions summary');
$check(str_contains($empty, 'No active calls') && str_contains($empty, 'No registrations'),
    'empty states render instead of broken tables');

// 3b) Server resources card, with real stats and with none available.
$resources = $renderStatus([
    'online'      => true,
    'version'     => ['version' => '20.5.0', 'raw' => 'Asterisk 20.5.0'],
    'uptime'      => '3 days, 4:05:06',
    'channels'    => [],
    'contacts'    => [],
    'conferences' => [],
    'queues'      => [],
    'configDir'   => '/etc/asterisk/asterisk_panel',
    'extIps'      => [],
    'serverStats' => [
        'cpu'     => 64.2,
        'memory'  => ['total' => 8 * 1024 ** 3, 'used' => 6 * 1024 ** 3, 'available' => 2 * 1024 ** 3, 'percent' => 75.0],
        'disk'    => ['total' => 50 * 1024 ** 3, 'free' => 30 * 1024 ** 3, 'used' => 20 * 1024 ** 3, 'percent' => 40.0],
        'network' => ['rx' => 10240.0, 'tx' => 2048.0],
    ],
]);
$check(str_contains($resources, 'Server resources') && str_contains($resources, '64.2%'),
    'server resources card renders with live stats');
$check(str_contains($resources, 'id="srvMemBar" class="warn"'), 'a 75% memory load renders an amber meter');
$check(str_contains($resources, 'Sending 2 kB/s'), 'network tile reports the transmit rate');
$check(str_contains($chartHost($resources), 'Not enough samples yet'),
    'a status page with no history shows the graph empty state');
$check(str_contains($resources, '/system/server-stats') && str_contains($resources, 'visibilitychange'),
    'the status page polls for realtime updates and pauses when the tab is hidden');
$check(!str_contains($resources, 'Something went wrong'), 'server resource card never breaks the status page');

// 3c) A populated 24-hour series renders the graph itself.
$graphed = $renderStatus([
    'online'      => true,
    'version'     => ['version' => '20.5.0', 'raw' => 'Asterisk 20.5.0'],
    'uptime'      => '',
    'channels'    => [],
    'contacts'    => [],
    'conferences' => [],
    'queues'      => [],
    'configDir'   => '/etc/asterisk/asterisk_panel',
    'extIps'      => [],
    'serverStats' => ['cpu' => null, 'memory' => null, 'disk' => null, 'network' => null],
    'serverSeries' => [
        ['t' => time() - 7200, 'cpu' => 15.0, 'mem' => 40.0, 'disk' => 62.0],
        ['t' => time() - 3600, 'cpu' => 45.0, 'mem' => 55.0, 'disk' => 62.0],
        ['t' => time(), 'cpu' => 25.0, 'mem' => 48.0, 'disk' => 62.5],
    ],
]);
$check(str_contains($graphed, '<svg class="chart"') && !str_contains($chartHost($graphed), 'Not enough samples yet'),
    'a populated history renders the 24-hour graph');
$chartHtml = '';
$chartAt = strpos($graphed, '<svg class="chart"');
if ($chartAt !== false) {
    $chartEnd = strpos($graphed, '</svg>', $chartAt);
    $chartHtml = substr($graphed, $chartAt, $chartEnd === false ? 4000 : $chartEnd - $chartAt + 6);
}
$check(substr_count($chartHtml, '<path ') === 3, 'the graph plots one path per metric');

$controllerSource = file_get_contents(BASE_PATH . '/app/Controllers/SystemController.php');
$check(!str_contains($controllerSource, "if (\$isGlobal) {\n                    \$version")
    && str_contains($controllerSource, '$version  = $mgr->version();'),
    'Asterisk version and uptime are fetched for tenant status viewers');
$check(str_contains($controllerSource, '$contacts = $tenantId ? $mgr->tenantContacts($tenantId) : $mgr->contacts();'),
    'an active tenant scopes status contacts even for Superadmin viewers');

// 4) Every icon referenced anywhere in the theme must exist on disk.
$missing = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASE_PATH . '/themes/default/views'));
foreach ($rii as $file) {
    if ($file->isDir() || !str_ends_with($file->getFilename(), '.php')) continue;
    $src = (string) file_get_contents($file->getPathname());
    if (!preg_match_all("/fragment\\(\\s*'([^']+)'/", $src, $m)) continue;
    foreach ($m[1] as $name) {
        if (!str_contains($name, 'icons/') || !preg_match('#^icons/[a-z0-9_-]+$#', $name)) continue;
        if (!is_file(BASE_PATH . '/themes/default/views/' . $name . '.php')) {
            $missing[] = basename($file->getPathname()) . ' -> ' . $name;
        }
    }
}
$check($missing === [], 'every static icon fragment referenced by a view exists' . ($missing ? ' (' . implode(', ', $missing) . ')' : ''));

echo "All system status smoke checks passed.\n";
