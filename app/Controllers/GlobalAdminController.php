<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** Global superadmin accounts, deliberately separate from tenant users. */
final class GlobalAdminController extends BaseController
{
    public function index(Request $request): Response
    {
        $rows = $this->db()->all(
            "SELECT id, username, email, active, last_login, last_login_ip, created_at
             FROM users WHERE role = 'superadmin' AND tenant_id IS NULL ORDER BY username"
        );
        return $this->view('global-admins/index', ['rows' => $rows]);
    }

    public function store(Request $request): Response
    {
        $data = [
            'username' => $request->str('username'), 'email' => $request->str('email'),
            'active' => $request->bool('enabled') ? 1 : 0,
        ];
        $password = (string) $request->input('password', '');
        $v = Validator::make($data + ['password' => $password], [
            'username' => 'required|alpha_dash|max:80', 'email' => 'nullable|email|max:160',
            'password' => 'required|min:8',
        ]);
        if ($v->fails()) { Flash::error($v->firstError()); return Response::redirect('/global-admins'); }
        if ($this->db()->scalar('SELECT COUNT(*) FROM users WHERE tenant_id IS NULL AND username = ?', [$data['username']])) {
            Flash::error('That global admin username already exists.');
            return Response::redirect('/global-admins');
        }
        $id = $this->db()->insert('users', $data + [
            'tenant_id' => null, 'password' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'superadmin', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit('global_admin.create', 'user', $id, ['username' => $data['username']]);
        Flash::success('Global admin created.');
        return Response::redirect('/global-admins');
    }

    public function update(Request $request): Response
    {
        $row = $this->find((int) $request->params['id']);
        if (!$row) { Flash::error('Global admin not found.'); return Response::redirect('/global-admins'); }
        $data = ['email' => $request->str('email'), 'active' => $request->bool('enabled') ? 1 : 0];
        $v = Validator::make($data, ['email' => 'nullable|email|max:160']);
        if ($v->fails()) { Flash::error($v->firstError()); return Response::redirect('/global-admins'); }
        if ((int) $row['id'] === auth()->id()) { $data['active'] = 1; }
        if ((int) $row['active'] === 1 && $data['active'] === 0 && $this->activeCount() <= 1) {
            Flash::error('At least one active global admin is required.');
            return Response::redirect('/global-admins');
        }
        $password = (string) $request->input('password', '');
        if ($password !== '' && strlen($password) < 8) {
            Flash::error('Password must be at least 8 characters; no changes were saved.');
            return Response::redirect('/global-admins');
        }
        $this->db()->update('users', (int) $row['id'], $data);
        if ($password !== '') {
            $this->db()->update('users', (int) $row['id'], ['password' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        $this->audit('global_admin.update', 'user', (int) $row['id']);
        Flash::success('Global admin updated.');
        return Response::redirect('/global-admins');
    }

    public function destroy(Request $request): Response
    {
        $row = $this->find((int) $request->params['id']);
        if (!$row) { return Response::redirect('/global-admins'); }
        if ((int) $row['id'] === auth()->id()) {
            Flash::error('You cannot delete your own global admin account.');
            return Response::redirect('/global-admins');
        }
        if ((int) $row['active'] === 1 && $this->activeCount() <= 1) {
            Flash::error('At least one active global admin is required.');
            return Response::redirect('/global-admins');
        }
        $this->db()->delete('users', (int) $row['id']);
        $this->audit('global_admin.delete', 'user', (int) $row['id'], ['username' => $row['username']]);
        Flash::success('Global admin deleted.');
        return Response::redirect('/global-admins');
    }

    private function find(int $id): ?array
    {
        return $this->db()->one("SELECT * FROM users WHERE id = ? AND role = 'superadmin' AND tenant_id IS NULL", [$id]);
    }

    private function activeCount(): int
    {
        return (int) $this->db()->scalar("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND tenant_id IS NULL AND active = 1");
    }
}
