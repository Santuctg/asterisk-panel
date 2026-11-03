<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Services\TenantSslManager;

final class TenantSslController extends BaseController
{
    public function index(Request $r): Response { return $this->view('tenants/ssl', ['ssl' => (new TenantSslManager((int)$r->params['id']))->publicState()]); }
    public function register(Request $r): Response { return $this->run($r, 'register', 'Tenant registered with ACME-DNS. Add the displayed CNAME.'); }
    public function verify(Request $r): Response { try { $ok=(new TenantSslManager((int)$r->params['id']))->verify(); Flash::add($ok?'success':'warning', $ok?'Tenant CNAME verified.':'Tenant CNAME is not visible yet.'); } catch(\Throwable $e){Flash::error($e->getMessage());} return $this->back($r); }
    public function issue(Request $r): Response { return $this->run($r, 'issue', 'Tenant certificate issued and HTTPS activated.'); }
    public function reset(Request $r): Response { return $this->run($r, 'reset', 'Tenant ACME-DNS registration cleared. Existing certificate was retained.'); }
    private function run(Request $r,string $method,string $message): Response { try {(new TenantSslManager((int)$r->params['id']))->$method();$this->audit('tenant.ssl.'.$method,'tenant',(int)$r->params['id']);Flash::success($message);}catch(\Throwable $e){Flash::error($e->getMessage());}return $this->back($r); }
    private function back(Request $r): Response { return Response::redirect('/tenants/'.(int)$r->params['id'].'/ssl'); }
}
