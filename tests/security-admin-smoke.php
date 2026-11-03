<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite'); putenv('DB_NAME=:memory:');
putenv('APP_PRIMARY_HOST=panel.example.test');
require BASE_PATH . '/app/bootstrap.php';
$app=App\Core\App::getInstance(); $app->boot(); $db=$app->db();
foreach(require BASE_PATH.'/resources/config/schema.php' as $sql){$db->exec($sql);}
$tenant=$db->insert('tenants',['name'=>'A','context'=>'a','domain'=>'a.example.test','enabled'=>1]);
$hash=password_hash('test-password',PASSWORD_DEFAULT);
$super=$db->insert('users',['tenant_id'=>null,'username'=>'rootadmin','email'=>'root@example.test','password'=>$hash,'role'=>'superadmin','active'=>1]);
$_SERVER['HTTP_HOST']='panel.example.test'; $_SESSION['active_tenant']=$tenant; $app->auth()->login($super);
$check=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException('FAIL: '.$label);echo 'PASS: '.$label.PHP_EOL;};

$admins=new App\Controllers\GlobalAdminController();
$page=$admins->index(new App\Core\Request('GET','/global-admins'));
$check($page->status()===200&&str_contains($page->body(),'rootadmin')&&!str_contains($page->body(),$hash),'global admin page hides password hashes');
$admins->store(new App\Core\Request('POST','/global-admins',[],[
    'username'=>'second-root','email'=>'second@example.test','password'=>'test-password','enabled'=>'1',
]));
$second=$db->one("SELECT * FROM users WHERE username='second-root' AND role='superadmin' AND tenant_id IS NULL");
$check($second!==null,'global admin can be created separately from tenants');
$request=new App\Core\Request('DELETE','/global-admins/'.$second['id']);$request->params=['id'=>$second['id']];$admins->destroy($request);
$check((int)$db->scalar("SELECT COUNT(*) FROM users WHERE username='second-root'")===0,'global admin can be deleted safely');

$sample="__JAIL__:asterisk\nStatus for the jail: asterisk\n|- Filter\n|  |- Currently failed:\t2\n|  `- Total failed:\t8\n`- Actions\n   |- Currently banned:\t1\n   |- Total banned:\t3\n   `- Banned IP list:\t203.0.113.10\n__JAIL__:asterisk-panel-login\n|- Currently failed: 0\n|- Total failed: 4\n|- Currently banned: 2\n|- Total banned: 5\n`- Banned IP list: 198.51.100.2 2001:db8::1\n";
$status=App\Services\Fail2banService::parseStatus($sample);
$check(($status['asterisk']['currently_banned']??0)===1&&($status['asterisk-panel-login']['banned_ips']??[])===['198.51.100.2','2001:db8::1'],'Fail2ban status output is parsed');
$controller=new App\Controllers\Fail2banController();
$securityPage=$controller->index(new App\Core\Request('GET','/fail2ban'));
$check($securityPage->status()===200&&str_contains($securityPage->body(),'Manually ban IP'),'Fail2ban management page renders without helper');
echo "All global-admin and Fail2ban checks passed.\n";
