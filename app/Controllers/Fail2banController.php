<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Services\Fail2banService;

final class Fail2banController extends BaseController
{
    public function index(Request $request): Response
    {
        $error = null; $jails = []; $whitelist = [];
        try { $service = new Fail2banService(); $jails = $service->status(); $whitelist = $service->whitelist(); }
        catch (\Throwable $e) { $error = $e->getMessage(); }
        return $this->view('fail2ban/index', ['jails'=>$jails, 'whitelist'=>$whitelist, 'error'=>$error, 'allowedJails'=>Fail2banService::JAILS]);
    }

    public function ban(Request $request): Response { return $this->run($request, 'ban'); }
    public function unban(Request $request): Response { return $this->run($request, 'unban'); }
    public function whitelistAdd(Request $request): Response { return $this->runWhitelist($request, 'whitelist-add'); }
    public function whitelistRemove(Request $request): Response { return $this->runWhitelist($request, 'whitelist-remove'); }

    private function run(Request $request, string $action): Response
    {
        $jail=$request->str('jail'); $ip=$request->str('ip');
        try {
            if ($action === 'ban' && filter_var($ip, FILTER_VALIDATE_IP) !== false
                && hash_equals($request->ip(), $ip)) {
                throw new \RuntimeException('You cannot ban the IP address of your current session.');
            }
            (new Fail2banService())->action($action,$jail,$ip);
            $this->audit('fail2ban.'.$action,'ip',null,['jail'=>$jail,'ip'=>$ip]);
            Flash::success(($action==='ban'?'Banned ':'Unbanned ').$ip.' in '.$jail.'.');
        } catch (\Throwable $e) { Flash::error($e->getMessage()); }
        return Response::redirect('/fail2ban');
    }

    private function runWhitelist(Request $request, string $action): Response
    {
        try {
            $ip = $request->str('ip');
            (new Fail2banService())->whitelistAction($action, $ip);
            $this->audit('fail2ban.'.$action, 'ip', null, ['ip' => $ip]);
            Flash::success($action === 'whitelist-add' ? 'IP added to the Fail2ban whitelist.' : 'IP removed from the Fail2ban whitelist.');
        } catch (\Throwable $e) { Flash::error($e->getMessage()); }
        return Response::redirect('/fail2ban');
    }
}
