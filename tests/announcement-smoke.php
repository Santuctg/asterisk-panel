<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
$testRoot = BASE_PATH . '/storage/cache/tests/announcement-' . getmypid();
$configDir = $testRoot . '/asterisk-config';
$audioDir = $testRoot . '/announcements';
@mkdir($configDir, 0775, true);
@mkdir($audioDir . '/a', 0775, true);
@mkdir($audioDir . '/b', 0775, true);
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('AMI_HOST=127.0.0.1');
putenv('AMI_PORT=1');
putenv('ASTERISK_CONFIG_DIR=' . $configDir);
putenv('ASTERISK_ANNOUNCEMENT_DIR=' . $audioDir);
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) { $db->exec($sql); }
$a = $db->insert('tenants', ['name'=>'A','context'=>'a','domain'=>'a.example.test','enabled'=>1]);
$b = $db->insert('tenants', ['name'=>'B','context'=>'b','domain'=>'b.example.test','enabled'=>1]);
$user = $db->insert('users', ['tenant_id'=>$a,'username'=>'admin','password'=>'x','role'=>'admin','active'=>1]);
$userB = $db->insert('users', ['tenant_id'=>$b,'username'=>'admin-b','password'=>'x','role'=>'admin','active'=>1]);
$aa = $db->insert('announcements', ['tenant_id'=>$a,'name'=>'Tenant A welcome','filename'=>'a.wav','sound_path'=>'asterisk-panel/a/a','enabled'=>1]);
$ab = $db->insert('announcements', ['tenant_id'=>$b,'name'=>'Tenant B secret','filename'=>'b.wav','sound_path'=>'asterisk-panel/b/b','enabled'=>1]);
file_put_contents($audioDir . '/a/a.wav', 'RIFF-test-audio');
file_put_contents($audioDir . '/b/b.wav', 'RIFF-secret-audio');
$group = $db->insert('ring_groups', ['tenant_id'=>$a,'extension'=>'600','name'=>'Sales','announcement_id'=>$aa,'enabled'=>1]);
$db->update('announcements', $aa, ['dest_type'=>'ringgroup', 'dest_id'=>$group]);
$app->auth()->login($user);
$_SERVER['HTTP_HOST'] = 'a.example.test';
$controller = new App\Controllers\AnnouncementController();

$failedUpload = new App\Core\Request('POST', '/announcements', [], ['name'=>'Must not exist'], [], [
    'audio' => ['name'=>'too-large.wav', 'tmp_name'=>'', 'size'=>0, 'error'=>UPLOAD_ERR_INI_SIZE],
]);
$controller->store($failedUpload);
if ((int)$db->scalar('SELECT COUNT(*) FROM announcements WHERE tenant_id = ? AND name = ?', [$a, 'Must not exist'])) throw new RuntimeException('Failed audio upload created an announcement option');

$foreign = new App\Core\Request('DELETE', '/announcements/'.$ab); $foreign->params=['id'=>$ab];
$controller->destroy($foreign);
if (!(int)$db->scalar('SELECT COUNT(*) FROM announcements WHERE id = ?', [$ab])) throw new RuntimeException('Cross-tenant announcement was deleted');

$audioRequest = new App\Core\Request('GET', '/announcements/'.$aa.'/audio'); $audioRequest->params=['id'=>$aa];
$audioResponse = $controller->audio($audioRequest);
if ($audioResponse->status() !== 200
    || ($audioResponse->headers()['Content-Type'] ?? '') !== 'audio/wav'
    || ($audioResponse->headers()['X-Accel-Redirect'] ?? '') !== '/_protected/announcements/a/a.wav') {
    throw new RuntimeException('Own-tenant announcement audio was not authorized securely');
}
$app->auth()->login($userB);
$_SERVER['HTTP_HOST'] = 'b.example.test';
$foreignAudio = new App\Core\Request('GET', '/announcements/'.$aa.'/audio'); $foreignAudio->params=['id'=>$aa];
if ($controller->audio($foreignAudio)->status() !== 404) throw new RuntimeException('Cross-tenant announcement audio was exposed');
$app->auth()->login($user);
$_SERVER['HTTP_HOST'] = 'a.example.test';

$page = (new App\Controllers\MusicOnHoldController())->index(new App\Core\Request('GET', '/moh'));
if (!str_contains($page->body(), 'Tenant A welcome') || str_contains($page->body(), 'Tenant B secret')) throw new RuntimeException('Announcement library crossed tenant scope');
$dialplan = (new App\Services\Asterisk\ConfigGenerator())->renderDialplan();
if (!str_contains($dialplan, '[a-announcement-'.$aa.']')
    || !str_contains($dialplan, 'Playback(asterisk-panel/a/a)')
    || !str_contains($dialplan, 'Gosub(a,600,1)')) throw new RuntimeException('Routable announcement dialplan is invalid');
$routeOptions = (new App\Controllers\RouteController())->destinationOptions();
if (($routeOptions['announcement'][0]['id'] ?? null) !== $aa) throw new RuntimeException('Announcement is not available as a destination');

$own = new App\Core\Request('DELETE', '/announcements/'.$aa); $own->params=['id'=>$aa];
$controller->destroy($own);
if ((int)$db->scalar('SELECT COUNT(*) FROM announcements WHERE id = ?', [$aa])) throw new RuntimeException('Own announcement was not deleted');
if ($db->scalar('SELECT announcement_id FROM ring_groups WHERE id = ?', [$group]) !== null) throw new RuntimeException('Deleted announcement remained assigned');

$ringForm = file_get_contents(BASE_PATH . '/themes/default/views/ringgroups/form.php');
$routesView = file_get_contents(BASE_PATH . '/themes/default/views/routes/index.php');
$queueView = file_get_contents(BASE_PATH . '/themes/default/views/callcenter/index.php');
if (str_contains($ringForm, 'Call tune while ringing') || !str_contains($ringForm, 'Custom prompt') || !str_contains($ringForm, 'Add Audio')) throw new RuntimeException('Ring group custom prompt UI is invalid');
if (!str_contains($routesView, "field_select('announcement_id'") || !str_contains($queueView, "field_select('announcement_id'")) throw new RuntimeException('Announcement selectors are missing');
$mohView = file_get_contents(BASE_PATH . '/themes/default/views/moh/index.php');
if (!str_contains($mohView, 'option is created only after the audio uploads successfully')
    || !str_contains($mohView, 'data-announcement-play')
    || !str_contains($mohView, '/audio?download=1')) throw new RuntimeException('Announcement browser audio controls are invalid');
foreach (glob($configDir . '/*') ?: [] as $file) { if (is_file($file)) { @unlink($file); } }
@unlink($audioDir . '/a/a.wav');
@unlink($audioDir . '/b/b.wav');
@rmdir($audioDir . '/a');
@rmdir($audioDir . '/b');
@rmdir($audioDir);
@rmdir($configDir);
@rmdir($testRoot);
echo "All announcement tenant and UI checks passed.\n";
