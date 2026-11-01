<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Panel user accounts. Admins manage users in their own tenant; superadmins
 * manage everyone.
 */
final class UserController extends BaseController
{
    /** GET /users */
    public function index(Request $request): Response
    {
        $tid = $this->tenantId();

        if (auth()->isSuperAdmin()) {
            $rows = $this->db()->all(
                'SELECT u.*, t.name AS tenant_name FROM users u
                 LEFT JOIN tenants t ON t.id = u.tenant_id ORDER BY u.username'
            );
            $tenants = $this->db()->all('SELECT id, name FROM tenants WHERE enabled = 1 ORDER BY name');
        } else {
            $rows = $this->db()->all(
                'SELECT u.*, t.name AS tenant_name FROM users u
                 LEFT JOIN tenants t ON t.id = u.tenant_id
                 WHERE u.tenant_id = ? ORDER BY u.username',
                [$tid]
            );
            $tenants = $this->db()->all('SELECT id, name FROM tenants WHERE id = ?', [$tid]);
        }

        return $this->view('users/index', ['rows' => $rows, 'tenants' => $tenants]);
    }

    /** POST /users */
    public function store(Request $request): Response
    {
        $tid = $this->tenantId();

        $data = [
            'tenant_id' => $request->int('tenant_id') ?: $tid,
            'username'  => $request->str('username'),
            'email'     => $request->str('email'),
            'role'      => $request->str('role', 'user'),
            'active'    => $request->bool('active', true) ? 1 : 0,
        ];

        $password = (string) $request->input('password', '');

        $v = Validator::make($data + ['password' => $password], [
            'username' => 'required|alpha_dash|max:80|unique:users,username',
            'email'    => 'nullable|email|max:160',
            'role'     => 'required|in:user,admin,superadmin',
            'password' => 'required|min:8',
        ]);

        // Non-superadmins cannot create superadmins.
        if (!auth()->isSuperAdmin() && $data['role'] === 'superadmin') {
            $v = Validator::make(['role' => 'x'], ['role' => 'in:user,admin']);
        }

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/users');
        }

        $data['password']   = password_hash($password, PASSWORD_DEFAULT);
        $data['created_at'] = date('Y-m-d H:i:s');

        $id = $this->db()->insert('users', $data);

        $this->clearOld();
        $this->audit('user.create', 'user', $id, ['username' => $data['username'], 'role' => $data['role']]);

        Flash::success("User \"{$data['username']}\" created.");
        return Response::redirect('/users');
    }

    /** POST /users/{id} */
    public function update(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/users');
        }

        $data = [
            'email'  => $request->str('email'),
            'role'   => $request->str('role', $row['role']),
            'active' => $request->bool('active') ? 1 : 0,
        ];

        if (!auth()->isSuperAdmin()) {
            // Admins can't grant or strip superadmin.
            if ($data['role'] === 'superadmin' || $row['role'] === 'superadmin') {
                $data['role'] = $row['role'];
            }
        }

        // Prevent locking yourself out / deactivating yourself.
        if ((int) $row['id'] === auth()->id()) {
            $data['active'] = 1;
            $data['role']   = $row['role'];
        }

        $this->db()->update('users', (int) $row['id'], $data);

        // Optional password reset.
        $new = (string) $request->input('password', '');
        if ($new !== '') {
            if (strlen($new) < 8) {
                Flash::error('Password must be at least 8 characters; password not changed.');
                return Response::redirect('/users');
            }
            $this->db()->update('users', (int) $row['id'], [
                'password' => password_hash($new, PASSWORD_DEFAULT),
            ]);
        }

        $this->audit('user.update', 'user', (int) $row['id']);
        Flash::success('User updated.');
        return Response::redirect('/users');
    }

    /** DELETE /users/{id} */
    public function destroy(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/users');
        }

        if ((int) $row['id'] === auth()->id()) {
            Flash::error('You cannot delete your own account.');
            return Response::redirect('/users');
        }

        $this->db()->delete('users', (int) $row['id']);
        $this->audit('user.delete', 'user', (int) $row['id'], ['username' => $row['username']]);

        Flash::success('User deleted.');
        return Response::redirect('/users');
    }

    private function findScoped(int $id): ?array
    {
        if (auth()->isSuperAdmin()) {
            return $this->db()->one('SELECT * FROM users WHERE id = ?', [$id]);
        }

        $tid = $this->tenantId();
        return $this->db()->one('SELECT * FROM users WHERE id = ? AND tenant_id = ?', [$id, $tid]);
    }
}
