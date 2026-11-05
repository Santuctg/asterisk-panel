<?php

declare(strict_types=1);

$base = dirname(__DIR__);
$mohRoot = $base . '/storage/cache/moh-smoke';
@mkdir($mohRoot . '/tenant_a/shared/.disabled', 0775, true);
@mkdir($mohRoot . '/tenant_b/shared', 0775, true);
file_put_contents($mohRoot . '/tenant_a/shared/active.wav', str_repeat('a', 100));
file_put_contents($mohRoot . '/tenant_a/shared/.disabled/off.wav', str_repeat('b', 100));
file_put_contents($mohRoot . '/tenant_b/shared/secret.wav', str_repeat('c', 100));

define('BASE_PATH', $base);
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('ASTERISK_MOH_PATH=' . str_replace('\\', '/', $mohRoot));
putenv('AMI_HOST=127.0.0.1');
putenv('AMI_PORT=1');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) { $db->exec($sql); }
$a = $db->insert('tenants', ['name'=>'A','context'=>'tenant_a','domain'=>'a.example.test','enabled'=>1]);
$b = $db->insert('tenants', ['name'=>'B','context'=>'tenant_b','domain'=>'b.example.test','enabled'=>1]);
$user = $db->insert('users', ['tenant_id'=>$a,'username'=>'admin','password'=>'x','role'=>'admin','active'=>1]);
$classA = $db->insert('moh_classes', ['tenant_id'=>$a,'name'=>'shared','random'=>1]);
$classB = $db->insert('moh_classes', ['tenant_id'=>$b,'name'=>'shared','random'=>1]);
$app->auth()->login($user);
$_SERVER['HTTP_HOST'] = 'a.example.test';
$controller = new App\Controllers\MusicOnHoldController();

$page = $controller->index(new App\Core\Request('GET', '/moh', ['class'=>$classA]));
if (!str_contains($page->body(), 'active.wav') || !str_contains($page->body(), 'off.wav') || str_contains($page->body(), 'secret.wav')) throw new RuntimeException('MOH page crossed tenant scope or missed tracks');
if (!str_contains($page->body(), 'AI Generated Speech') || !str_contains($page->body(), 'data-track-play')) throw new RuntimeException('MOH track tools are missing');

$own = new App\Core\Request('GET', '/moh/'.$classA.'/tracks/active.wav');
$own->params = ['id'=>$classA, 'filename'=>'active.wav'];
$response = $controller->trackAudio($own);
if ($response->status() !== 200 || !str_contains((string) ($response->headers()['X-Accel-Redirect'] ?? ''), '/_protected/moh/tenant_a/shared/active.wav')) throw new RuntimeException('Authorized MOH audio response is invalid');

$foreign = new App\Core\Request('GET', '/moh/'.$classB.'/tracks/secret.wav');
$foreign->params = ['id'=>$classB, 'filename'=>'secret.wav'];
if ($controller->trackAudio($foreign)->status() !== 404) throw new RuntimeException('Cross-tenant MOH audio was exposed');

$config = (new App\Services\Asterisk\ConfigGenerator())->renderMusicOnHold();
if (!str_contains($config, '[tenant'.$a.'_shared]') || !str_contains($config, '[tenant'.$b.'_shared]')) throw new RuntimeException('Asterisk MOH classes are not tenant namespaced');

echo "All Music on Hold tenant, audio, and UI checks passed.\n";
