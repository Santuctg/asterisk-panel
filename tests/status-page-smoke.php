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

// 2) Asterisk online with live data, including the connected-extension stat card.
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
$check(str_contains($online, 'Connected extensions') && str_contains($online, '1 extension(s) connected'),
    'connected extensions stat card renders its value');
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

$check(str_contains($empty, '0 extension(s) connected'), 'empty online status page shows zero connected extensions');
$check(str_contains($empty, 'No active calls') && str_contains($empty, 'No registrations'),
    'empty states render instead of broken tables');

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
