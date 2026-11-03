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
            'tenant_id' => auth()->isSuperAdmin() ? ($request->int('tenant_id') ?: null) : $tid,
            'username'  => $request->str('username'),
            'email'     => $request->str('email'),
            'role'      => $request->str('role', 'user'),
            'active'    => $request->bool('enabled') ? 1 : 0,
        ];

        $password = (string) $request->input('password', '');

        $v = Validator::make($data + ['password' => $password], [
            'username' => 'required|alpha_dash|max:80|unique:users,username',
            'email'    => 'nullable|email|max:160',
            'role'     => 'required|in:user,admin,superadmin',
            'password' => 'required|min:8',
        ]);

        if (!auth()->isSuperAdmin() && $data['role'] === 'superadmin') {
            Flash::error('Tenant admins cannot create super admins.');
            return Response::redirect('/users');
        }

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/users');
        }

        if ($data['role'] === 'superadmin') {
            $data['tenant_id'] = null;
        } elseif (!$this->validTenantId($data['tenant_id'])) {
            Flash::error('User and admin roles must be assigned to an active tenant.');
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
            'active' => $request->bool('enabled') ? 1 : 0,
        ];
        if (auth()->isSuperAdmin()) {
            $data['tenant_id'] = $request->int('tenant_id') ?: null;
        }

        $v = Validator::make($data, [
            'email' => 'nullable|email|max:160',
            'role' => 'required|in:user,admin,superadmin',
        ]);
        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/users');
        }

        if (!auth()->isSuperAdmin()) {
            if ($data['role'] === 'superadmin') {
                Flash::error('Tenant admins cannot assign the super admin role.');
                return Response::redirect('/users');
            }
        }

        // Prevent locking yourself out / deactivating yourself.
        if ((int) $row['id'] === auth()->id()) {
            $data['active'] = 1;
            $data['role']   = $row['role'];
            if (auth()->isSuperAdmin()) {
                $data['tenant_id'] = $row['tenant_id'];
            }
        }

        if ($data['role'] === 'superadmin') {
            $data['tenant_id'] = null;
        } else {
            $assignedTenant = array_key_exists('tenant_id', $data) ? $data['tenant_id'] : $row['tenant_id'];
            if (!$this->validTenantId($assignedTenant)) {
                Flash::error('User and admin roles must be assigned to an active tenant.');
                return Response::redirect('/users');
            }
        }

        if ($this->wouldRemoveLastSuperAdmin($row, $data)) {
            Flash::error('At least one active super admin account is required.');
            return Response::redirect('/users');
        }

        // Validate an optional password before changing any account fields.
        $new = (string) $request->input('password', '');
        if ($new !== '' && strlen($new) < 8) {
            Flash::error('Password must be at least 8 characters; no changes were saved.');
            return Response::redirect('/users');
        }

        $this->db()->update('users', (int) $row['id'], $data);

        // Optional password reset.
        if ($new !== '') {
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

        if ($row['role'] === 'superadmin' && (int) $row['active'] === 1
            && (int) $this->db()->scalar("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND active = 1") <= 1) {
            Flash::error('At least one active super admin account is required.');
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

    private function validTenantId(mixed $tenantId): bool
    {
        return is_numeric($tenantId) && (int) $tenantId > 0
            && (int) $this->db()->scalar('SELECT COUNT(*) FROM tenants WHERE id = ? AND enabled = 1', [(int) $tenantId]) === 1;
    }

    private function wouldRemoveLastSuperAdmin(array $row, array $data): bool
    {
        if ($row['role'] !== 'superadmin' || (int) $row['active'] !== 1) {
            return false;
        }
        if (($data['role'] ?? $row['role']) === 'superadmin' && (int) ($data['active'] ?? $row['active']) === 1) {
            return false;
        }
        return (int) $this->db()->scalar("SELECT COUNT(*) FROM users WHERE role = 'superadmin' AND active = 1") <= 1;
    }
}
