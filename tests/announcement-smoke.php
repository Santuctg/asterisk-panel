<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('AMI_HOST=127.0.0.1');
putenv('AMI_PORT=1');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) { $db->exec($sql); }
$a = $db->insert('tenants', ['name'=>'A','context'=>'a','domain'=>'a.example.test','enabled'=>1]);
$b = $db->insert('tenants', ['name'=>'B','context'=>'b','domain'=>'b.example.test','enabled'=>1]);
$user = $db->insert('users', ['tenant_id'=>$a,'username'=>'admin','password'=>'x','role'=>'admin','active'=>1]);
$aa = $db->insert('announcements', ['tenant_id'=>$a,'name'=>'Tenant A welcome','filename'=>'a.wav','sound_path'=>'asterisk-panel/a/a','enabled'=>1]);
$ab = $db->insert('announcements', ['tenant_id'=>$b,'name'=>'Tenant B secret','filename'=>'b.wav','sound_path'=>'asterisk-panel/b/b','enabled'=>1]);
$group = $db->insert('ring_groups', ['tenant_id'=>$a,'extension'=>'600','name'=>'Sales','announcement_id'=>$aa,'enabled'=>1]);
$app->auth()->login($user);
$_SERVER['HTTP_HOST'] = 'a.example.test';
$controller = new App\Controllers\AnnouncementController();

$foreign = new App\Core\Request('DELETE', '/announcements/'.$ab); $foreign->params=['id'=>$ab];
$controller->destroy($foreign);
if (!(int)$db->scalar('SELECT COUNT(*) FROM announcements WHERE id = ?', [$ab])) throw new RuntimeException('Cross-tenant announcement was deleted');

$page = (new App\Controllers\MusicOnHoldController())->index(new App\Core\Request('GET', '/moh'));
if (!str_contains($page->body(), 'Tenant A welcome') || str_contains($page->body(), 'Tenant B secret')) throw new RuntimeException('Announcement library crossed tenant scope');

$own = new App\Core\Request('DELETE', '/announcements/'.$aa); $own->params=['id'=>$aa];
$controller->destroy($own);
if ((int)$db->scalar('SELECT COUNT(*) FROM announcements WHERE id = ?', [$aa])) throw new RuntimeException('Own announcement was not deleted');
if ($db->scalar('SELECT announcement_id FROM ring_groups WHERE id = ?', [$group]) !== null) throw new RuntimeException('Deleted announcement remained assigned');

$ringForm = file_get_contents(BASE_PATH . '/themes/default/views/ringgroups/form.php');
$routesView = file_get_contents(BASE_PATH . '/themes/default/views/routes/index.php');
$queueView = file_get_contents(BASE_PATH . '/themes/default/views/callcenter/index.php');
if (str_contains($ringForm, 'Call tune while ringing') || !str_contains($ringForm, "field_select('announcement_id'")) throw new RuntimeException('Ring group announcement UI is invalid');
if (!str_contains($routesView, "field_select('announcement_id'") || !str_contains($queueView, "field_select('announcement_id'")) throw new RuntimeException('Announcement selectors are missing');
echo "All announcement tenant and UI checks passed.\n";
