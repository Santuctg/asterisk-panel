<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite');
putenv('DB_NAME=:memory:');
putenv('APP_PRIMARY_HOST=panel.example.test');
require BASE_PATH . '/app/bootstrap.php';

$app = App\Core\App::getInstance();
$app->boot();
$db = $app->db();
foreach (require BASE_PATH . '/resources/config/schema.php' as $sql) { $db->exec($sql); }
$a = $db->insert('tenants', ['name'=>'A','context'=>'a','domain'=>'a.example.test','enabled'=>1]);
$b = $db->insert('tenants', ['name'=>'B','context'=>'b','domain'=>'b.example.test','enabled'=>1]);
$admin = $db->insert('users', [
    'tenant_id'=>$a,'username'=>'admin','password'=>password_hash('test-password', PASSWORD_DEFAULT),'role'=>'admin','active'=>1,
]);
$a1 = $db->insert('cdr', ['tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1001','dst'=>'1002','uniqueid'=>'a1']);
$a2 = $db->insert('cdr', ['tenant_id'=>$a,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1002','dst'=>'1003','uniqueid'=>'a2']);
$b1 = $db->insert('cdr', ['tenant_id'=>$b,'calldate'=>date('Y-m-d H:i:s'),'src'=>'1001','dst'=>'1002','uniqueid'=>'b1']);
$_SERVER['HTTP_HOST']='a.example.test';
$app->auth()->login($admin);
$controller = new App\Controllers\CdrController();
$page = $controller->index(new App\Core\Request('GET', '/cdr'));
if (!str_contains($page->body(), 'Delete all history')) throw new RuntimeException('CDR delete controls missing');
$request = new App\Core\Request('DELETE', '/cdr/'.$a1);
$request->params=['id'=>$a1];
$controller->destroy($request);
if ((int)$db->scalar('SELECT COUNT(*) FROM cdr WHERE id = ?', [$a1]) !== 0) throw new RuntimeException('Own CDR was not deleted');
$request = new App\Core\Request('DELETE', '/cdr/'.$b1); $request->params=['id'=>$b1];
$controller->destroy($request);
if ((int)$db->scalar('SELECT COUNT(*) FROM cdr WHERE id = ?', [$b1]) !== 1) throw new RuntimeException('Cross-tenant CDR was deleted');
$controller->clear(new App\Core\Request('DELETE', '/cdr'));
if ((int)$db->scalar('SELECT COUNT(*) FROM cdr WHERE tenant_id = ?', [$a]) !== 0) throw new RuntimeException('Tenant CDR clear failed');
if ((int)$db->scalar('SELECT COUNT(*) FROM cdr WHERE tenant_id = ?', [$b]) !== 1) throw new RuntimeException('Tenant CDR clear crossed scope');
echo "All tenant-scoped CDR delete checks passed.\n";
