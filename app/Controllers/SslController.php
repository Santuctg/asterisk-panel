<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Services\SslManager;

final class SslController extends BaseController
{
    public function index(Request $request): Response
    {
        return $this->view('system/ssl', ['ssl' => (new SslManager())->publicState()]);
    }

    public function register(Request $request): Response
    {
        try {
            $domain = $request->str('domain');
            $email = $request->str('email');
            (new SslManager())->register($domain, $email);
            $this->audit('ssl.acme_dns.register', 'system', null, ['domain' => $domain]);
            Flash::success('Hosted ACME-DNS registration created. Add the displayed CNAME record, then verify it.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/system/ssl');
    }

    public function verify(Request $request): Response
    {
        try {
            if ((new SslManager())->verifyCname()) {
                Flash::success('CNAME verified. The domain is ready for certificate issuance.');
            } else {
                Flash::warning('CNAME is not visible yet. Check the exact name and target, then allow DNS propagation time.');
            }
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/system/ssl');
    }

    public function issue(Request $request): Response
    {
        try {
            $state = (new SslManager())->issue();
            $this->audit('ssl.certificate.issue', 'system', null, ['domain' => $state['domain'] ?? null]);
            Flash::success('Certificate issued and HTTPS activated. Reload this page over HTTPS.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/system/ssl');
    }

    public function reset(Request $request): Response
    {
        try {
            (new SslManager())->reset();
            $this->audit('ssl.acme_dns.reset', 'system');
            Flash::success('Panel registration cleared. Existing certificates were not deleted.');
        } catch (\Throwable $e) {
            Flash::error($e->getMessage());
        }
        return Response::redirect('/system/ssl');
    }
}
