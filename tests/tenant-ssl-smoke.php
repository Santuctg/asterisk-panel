<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__)); define('PUBLIC_PATH', BASE_PATH . '/public');
putenv('DB_DRIVER=sqlite'); putenv('DB_NAME=:memory:'); putenv('APP_URL=https://panel.example.com');
require BASE_PATH . '/app/bootstrap.php';
$app=App\Core\App::getInstance(); $app->boot(); $db=$app->db();
foreach(require BASE_PATH.'/resources/config/schema.php' as $sql){$db->exec($sql);}
$tid=$db->insert('tenants',['name'=>'Tenant','context'=>'tenant','domain'=>'pbx.customer.example','contact_email'=>'admin@customer.example','enabled'=>1]);
$state=(new App\Services\TenantSslManager($tid))->publicState();
if (($state['tenant']['domain']??'')!=='pbx.customer.example' || isset($state['password'])) {throw new RuntimeException('Tenant SSL state scope failed');}
$routes=file_get_contents(BASE_PATH.'/resources/routes.php');
if (!str_contains($routes,"/tenants/{id}/ssl/issue")) {throw new RuntimeException('Tenant SSL route missing');}
$helper=file_get_contents(BASE_PATH.'/scripts/acme-dns-ssl-helper.sh');
if (!str_contains($helper,'issue-tenant') || !str_contains($helper,'nginx-tenant-https.conf.template')) {throw new RuntimeException('Restricted tenant helper missing');}
$view=file_get_contents(BASE_PATH.'/themes/default/views/tenants/ssl.php');
if (!str_contains($view, "partials/fields.php")) {throw new RuntimeException('Tenant SSL form helpers missing');}
echo "All tenant SSL smoke checks passed.\n";
