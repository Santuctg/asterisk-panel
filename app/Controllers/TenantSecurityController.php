<?php
declare(strict_types=1);
namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;

final class TenantSecurityController extends BaseController
{
    public function index(Request $request): Response
    {
        $tid=$this->requireTenant(); if(!$tid)return Response::redirect('/dashboard');
        $rows=$this->db()->all('SELECT b.*,u.username AS created_by_name FROM tenant_login_bans b LEFT JOIN users u ON u.id=b.created_by WHERE b.tenant_id=? ORDER BY b.created_at DESC',[$tid]);
        return $this->view('tenant-security/index',['rows'=>$rows]);
    }

    public function store(Request $request): Response
    {
        $tid=$this->requireTenant(); $ip=$request->str('ip');
        if(!$tid)return Response::redirect('/tenant-security');
        if(filter_var($ip,FILTER_VALIDATE_IP)===false){Flash::error('Enter a valid IPv4 or IPv6 address.');return Response::redirect('/tenant-security');}
        if(hash_equals($request->ip(),$ip)){Flash::error('You cannot block the IP address of your current session.');return Response::redirect('/tenant-security');}
        if($this->db()->scalar('SELECT COUNT(*) FROM tenant_login_bans WHERE tenant_id=? AND ip=?',[$tid,$ip])){Flash::warning('That IP is already blocked for this tenant.');return Response::redirect('/tenant-security');}
        $id=$this->db()->insert('tenant_login_bans',['tenant_id'=>$tid,'ip'=>$ip,'reason'=>$request->str('reason'),'created_by'=>auth()->id(),'created_at'=>date('Y-m-d H:i:s')]);
        $this->audit('tenant_security.block','tenant_login_ban',$id,['tenant_id'=>$tid,'ip'=>$ip]);
        Flash::success('IP blocked for this tenant domain.');return Response::redirect('/tenant-security');
    }

    public function destroy(Request $request): Response
    {
        $tid=$this->requireTenant();
        $row=$tid?$this->db()->one('SELECT * FROM tenant_login_bans WHERE id=? AND tenant_id=?',[(int)$request->params['id'],$tid]):null;
        if($row){$this->db()->delete('tenant_login_bans',(int)$row['id']);$this->audit('tenant_security.unblock','tenant_login_ban',(int)$row['id'],['tenant_id'=>$tid,'ip'=>$row['ip']]);Flash::success('IP unblocked for this tenant domain.');}
        return Response::redirect('/tenant-security');
    }
}
