<?php

declare(strict_types=1);

// Run: php tests/trunk-autofill-smoke.php
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) {
    $db->exec($sql);
}
$tenant = $db->insert('tenants', ['name' => 'Test', 'context' => 'test']);
$user = $db->insert('users', [
    'tenant_id' => $tenant, 'username' => 'panel-admin', 'password' => password_hash('test-only', PASSWORD_DEFAULT),
    'role' => 'superadmin', 'active' => 1,
]);
$db->insert('trunks', [
    'tenant_id' => $tenant, 'name' => 'Test trunk', 'host' => 'sip.example.com',
    'username' => 'provider-id', 'secret' => 'trunk-secret-not-for-html', 'enabled' => 1,
]);
$app->auth()->login($user);
$_GET['tab'] = 'trunks';
$html = (new App\Controllers\RouteController())->index(new App\Core\Request('GET', '/routes'))->body();
foreach ([
    [!str_contains($html, 'trunk-secret-not-for-html'), 'stored trunk secret is excluded from edit HTML'],
    [str_contains($html, 'name="sip_trunk_auth_id"'), 'separate SIP auth ID field'],
    [str_contains($html, 'name="sip_trunk_auth_secret"'), 'separate SIP secret field'],
    [str_contains($html, 'type="password" id="sip_trunk_auth_secret" name="sip_trunk_auth_secret"')
        && str_contains($html, 'autocomplete="new-password"'), 'trunk secret uses a blank password field without login autofill'],
    [str_contains($html, "form.elements.sip_trunk_auth_secret.value = ''")
        && !str_contains($html, 'data.secret'), 'existing trunk secret never populates the edit field'],
    [str_contains($html, '>Registration<') && str_contains($html, 'Unknown'), 'trunk registration status renders when AMI is unavailable'],
    [str_contains($html, '>Uptime<'), 'trunk uptime column renders'],
    [str_contains($html, 'data-modal-open="trunkMonitor1"') && str_contains($html, 'id="trunkMonitor1"'), 'trunk Monitor action opens its popup'],
    [str_contains($html, '>Active calls<') && str_contains($html, 'class="secret-eye-button"')
        && str_contains($html, 'data-secret-target="sip_trunk_auth_secret"'), 'trunks show live call counts and an inline password eye control'],
] as [$ok, $description]) {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    echo 'PASS: ' . $description . PHP_EOL;
}
$_GET['tab'] = 'outbound';
$outboundHtml = (new App\Controllers\RouteController())->index(new App\Core\Request('GET', '/routes'))->body();
if (!str_contains($outboundHtml, '<option value="1">Test trunk</option>')) {
    throw new RuntimeException('FAIL: outbound route selector renders trunk names and IDs');
}
echo "PASS: outbound route selector renders trunk names and IDs\n";
$timeRedirect = (new App\Controllers\RouteController())->storeTime(
    new App\Core\Request('POST', '/routes/time', [], ['name' => ''])
);
if (($timeRedirect->headers()['Location'] ?? '') !== '/routes?tab=time') {
    throw new RuntimeException('FAIL: time-condition validation returns to its originating tab');
}
echo "PASS: time-condition validation returns to its originating tab\n";
if (!str_contains($outboundHtml, '<textarea id="match_pattern" name="match_pattern"')
    || !str_contains($outboundHtml, 'Add up to 20 Asterisk patterns, one per line.')) {
    throw new RuntimeException('FAIL: outbound route supports multiple dial patterns');
}
echo "PASS: outbound route supports multiple dial patterns\n";
if (!str_contains($outboundHtml, 'id="addOutboundSchedule"')
    || !str_contains($outboundHtml, 'name="schedules"')
    || !str_contains($outboundHtml, 'Always allowed — no schedule restrictions.')) {
    throw new RuntimeException('FAIL: outbound route supports direct calling schedules');
}
echo "PASS: outbound route supports direct calling schedules\n";
if (!str_contains($outboundHtml, "var scheduleDays = ['sun','mon','tue','wed','thu','fri','sat']")
    || !str_contains($outboundHtml, "selectedDays.join('&')")
    || !str_contains($outboundHtml, 'Select at least one day for every outbound schedule.')) {
    throw new RuntimeException('FAIL: outbound schedules support individual weekday selection');
}
echo "PASS: outbound schedules support individual weekday selection\n";
$routeController = new App\Controllers\RouteController();
$patternParser = new ReflectionMethod($routeController, 'outboundPatterns');
$parsedPatterns = $patternParser->invoke($routeController, "_01xxxxxxxxx\n_8801XXXXXXXXX\n_01XXXXXXXXX\n_+x.");
$invalidPatterns = $patternParser->invoke($routeController, "_X.\ninvalid pattern");
if ($parsedPatterns !== ['_01XXXXXXXXX', '_8801XXXXXXXXX', '_+X.'] || $invalidPatterns !== null) {
    throw new RuntimeException('FAIL: multiple outbound patterns normalize and validate correctly');
}
echo "PASS: multiple outbound patterns normalize and validate correctly\n";
$scheduleParser = new ReflectionMethod($routeController, 'outboundSchedules');
$selectedSchedule = new App\Core\Request('POST', '/routes/outbound', [], [
    'schedules' => json_encode([[
        'name' => 'Selected days', 'days_of_week' => 'sun&mon&wed&sat',
        'start_time' => '09:00', 'end_time' => '17:00',
    ]], JSON_THROW_ON_ERROR),
]);
$legacySchedule = new App\Core\Request('POST', '/routes/outbound', [], [
    'schedules' => json_encode([[
        'name' => 'Weekdays', 'days_of_week' => 'mon-fri',
        'start_time' => '09:00', 'end_time' => '17:00',
    ]], JSON_THROW_ON_ERROR),
]);
$emptyDaysSchedule = new App\Core\Request('POST', '/routes/outbound', [], [
    'schedules' => json_encode([[
        'name' => 'No days', 'days_of_week' => '',
        'start_time' => '09:00', 'end_time' => '17:00',
    ]], JSON_THROW_ON_ERROR),
]);
$selectedResult = $scheduleParser->invoke($routeController, $selectedSchedule);
$legacyResult = $scheduleParser->invoke($routeController, $legacySchedule);
$emptyDaysResult = $scheduleParser->invoke($routeController, $emptyDaysSchedule);
if (($selectedResult[0]['days_of_week'] ?? '') !== 'sun&mon&wed&sat'
    || ($legacyResult[0]['days_of_week'] ?? '') !== 'mon&tue&wed&thu&fri'
    || $emptyDaysResult !== null) {
    throw new RuntimeException('FAIL: outbound weekday values serialize and validate correctly');
}
echo "PASS: outbound weekday values serialize and validate correctly\n";
$routeView = file_get_contents(BASE_PATH . '/themes/default/views/routes/index.php');
if (!str_contains($routeView, 'Registration uptime history') || !str_contains($routeView, 'Pattern examples')) {
    throw new RuntimeException('FAIL: trunk history and outbound route examples render');
}
echo "PASS: trunk history and outbound route examples render\n";

$extension = $db->insert('extensions', [
    'tenant_id' => $tenant, 'extension' => '201', 'name' => 'Desk 201', 'secret' => 'test-secret', 'enabled' => 1,
]);
$ringGroup = $db->insert('ring_groups', [
    'tenant_id' => $tenant, 'extension' => '600', 'name' => 'Sales', 'enabled' => 1,
]);
$db->insert('ring_group_members', ['ring_group_id' => $ringGroup, 'extension_id' => $extension, 'ordering' => 0]);
$extensionHtml = (new App\Controllers\ExtensionController())->index(new App\Core\Request('GET', '/extensions'))->body();
if (!str_contains($extensionHtml, 'data-modal-open="extensionMonitor' . $extension . '"')
    || !str_contains($extensionHtml, 'id="extensionMonitor' . $extension . '"')) {
    throw new RuntimeException('FAIL: extension Monitor action opens its popup');
}
echo "PASS: extension Monitor action opens its popup\n";
$_GET['tab'] = 'inbound';
$html = (new App\Controllers\RouteController())->index(new App\Core\Request('GET', '/routes'))->body();
foreach ([
    [str_contains($html, 'data-type="extension"') && str_contains($html, '201 — Desk 201'), 'extension destination group renders'],
    [str_contains($html, 'data-type="ringgroup"') && str_contains($html, '600 — Sales'), 'ring group destination group renders'],
    [str_contains($html, 'destination.replaceChildren()'), 'destination selector rebuilds by selected type'],
] as [$ok, $description]) {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $description);
    }
    echo 'PASS: ' . $description . PHP_EOL;
}
