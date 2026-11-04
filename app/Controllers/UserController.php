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

        $rows = $this->db()->all(
            'SELECT u.*, t.name AS tenant_name, r.name AS role_name FROM users u
             LEFT JOIN tenants t ON t.id = u.tenant_id
             LEFT JOIN roles r ON r.tenant_id = u.tenant_id AND r.slug = u.role
             WHERE u.tenant_id = ? ORDER BY u.username',
             [$tid]
        );
        $tenants = $this->db()->all('SELECT id, name FROM tenants WHERE id = ?', [$tid]);

        return $this->view('users/index', [
            'rows' => $rows, 'tenants' => $tenants, 'roleOptions' => $this->roleOptions($tid),
        ]);
    }

    /** POST /users */
    public function store(Request $request): Response
    {
        $tid = $this->tenantId();

        $data = [
            'tenant_id' => $tid,
            'username'  => $request->str('username'),
            'email'     => $request->str('email'),
            'role'      => $request->str('role', 'user'),
            'active'    => $request->bool('enabled') ? 1 : 0,
        ];

        $password = (string) $request->input('password', '');

        $v = Validator::make($data + ['password' => $password], [
            'username' => 'required|alpha_dash|max:80',
            'email'    => 'nullable|email|max:160',
            'role'     => 'required|max:20',
            'password' => 'required|min:8',
        ]);

        if ($data['role'] === 'superadmin') {
            Flash::error('Super admin accounts are not managed inside a tenant.');
            return Response::redirect('/users');
        }

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/users');
        }

        if (!$this->validTenantId($data['tenant_id'])) {
            Flash::error('User and admin roles must be assigned to an active tenant.');
            return Response::redirect('/users');
        }
        if (!$this->canAssignRole($data['role'], $data['tenant_id'])) {
            Flash::error('Select a valid role for this tenant.');
            return Response::redirect('/users');
        }
        $duplicate = $this->db()->scalar(
            'SELECT COUNT(*) FROM users WHERE tenant_id = ? AND username = ?', [$data['tenant_id'], $data['username']]
        );
        if ($duplicate) {
            Flash::error('That username already exists in this tenant.');
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
        $v = Validator::make($data, [
            'email' => 'nullable|email|max:160',
            'role' => 'required|max:20',
        ]);
        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/users');
        }

        if ($data['role'] === 'superadmin') {
            Flash::error('Super admin accounts are not managed inside a tenant.');
            return Response::redirect('/users');
        }

        // Prevent locking yourself out / deactivating yourself.
        if ((int) $row['id'] === auth()->id()) {
            $data['active'] = 1;
            $data['role']   = $row['role'];
        }

        $assignedTenant = $row['tenant_id'];
        if (!$this->validTenantId($assignedTenant)) {
            Flash::error('User and admin roles must be assigned to an active tenant.');
            return Response::redirect('/users');
        }
        $data['tenant_id'] = (int) $assignedTenant;
        if (!$this->canAssignRole($data['role'], $data['tenant_id'])) {
            Flash::error('Select a valid role for this tenant.');
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

        $this->db()->delete('users', (int) $row['id']);
        $this->audit('user.delete', 'user', (int) $row['id'], ['username' => $row['username']]);

        Flash::success('User deleted.');
        return Response::redirect('/users');
    }

    private function findScoped(int $id): ?array
    {
        if (auth()->isSuperAdmin()) {
            return $this->db()->one('SELECT * FROM users WHERE id = ? AND tenant_id = ?', [$id, $this->tenantId()]);
        }

        $tid = $this->tenantId();
        if (auth()->role() !== 'admin') {
            return $this->db()->one(
                "SELECT * FROM users WHERE id = ? AND tenant_id = ? AND role NOT IN ('admin', 'superadmin')",
                [$id, $tid]
            );
        }
        return $this->db()->one('SELECT * FROM users WHERE id = ? AND tenant_id = ?', [$id, $tid]);
    }

    private function validTenantId(mixed $tenantId): bool
    {
        return is_numeric($tenantId) && (int) $tenantId > 0
            && (int) $this->db()->scalar('SELECT COUNT(*) FROM tenants WHERE id = ? AND enabled = 1', [(int) $tenantId]) === 1;
    }

    private function canAssignRole(string $role, mixed $tenantId): bool
    {
        if ($role === 'superadmin') { return false; }
        if ($role === 'admin') {
            return in_array(auth()->role(), ['admin', 'superadmin'], true) && $this->validTenantId($tenantId);
        }
        if ($role === 'user') { return $this->validTenantId($tenantId); }
        return $this->validTenantId($tenantId)
            && (int) $this->db()->scalar('SELECT COUNT(*) FROM roles WHERE tenant_id = ? AND slug = ?', [(int) $tenantId, $role]) === 1;
    }

    private function roleOptions(?int $tenantId): array
    {
        $options = ['user' => 'User'];
        if (in_array(auth()->role(), ['admin', 'superadmin'], true)) {
            $options['admin'] = 'Admin';
        }
        if ($tenantId) {
            foreach ($this->db()->all('SELECT slug, name FROM roles WHERE tenant_id = ? ORDER BY name', [$tenantId]) as $role) {
                $options[$role['slug']] = $role['name'];
            }
        }
        return $options;
    }
}
