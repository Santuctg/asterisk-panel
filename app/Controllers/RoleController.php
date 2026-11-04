<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Support\Str;

/** Tenant-scoped built-in and custom role permissions. */
final class RoleController extends BaseController
{
    public function index(Request $request): Response
    {
        $tid = $this->requireTenant();
        if (!$tid) { return Response::redirect('/dashboard'); }
        $rows = $this->db()->all(
            'SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.tenant_id = r.tenant_id AND u.role = r.slug) AS user_count
             FROM roles r WHERE r.tenant_id = ? ORDER BY r.name', [$tid]
        );
        foreach ($rows as &$row) {
            $decoded = json_decode((string) $row['permissions'], true);
            $row['permissions_list'] = is_array($decoded) ? $decoded : [];
            $row['built_in'] = in_array($row['slug'], ['admin','user'], true);
        }
        unset($row);
        return $this->view('roles/index', ['rows' => $rows, 'abilities' => Auth::assignableAbilities()]);
    }

    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();
        if (!$tid) { return Response::redirect('/roles'); }
        $name = $request->str('name');
        $slug = substr(Str::slug($name), 0, 20);
        $v = Validator::make(['name' => $name, 'slug' => $slug], [
            'name' => 'required|max:80', 'slug' => 'required|alpha_dash|max:20',
        ]);
        if ($v->fails()) { Flash::error($v->firstError()); return Response::redirect('/roles'); }
        if (in_array($slug, ['user', 'admin', 'superadmin'], true)) {
            Flash::error('That name is reserved for a built-in role.');
            return Response::redirect('/roles');
        }
        if ($this->db()->scalar('SELECT COUNT(*) FROM roles WHERE tenant_id = ? AND (LOWER(name) = LOWER(?) OR slug = ?)', [$tid, $name, $slug])) {
            Flash::error('A role with that name already exists in this tenant.');
            return Response::redirect('/roles');
        }
        $permissions = $this->permissions($request);
        $id = $this->db()->insert('roles', [
            'tenant_id' => $tid, 'name' => $name, 'slug' => $slug,
            'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit('role.create', 'role', $id, ['name' => $name, 'permissions' => $permissions]);
        Flash::success("Role \"{$name}\" created.");
        return Response::redirect('/roles');
    }

    public function update(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) { Flash::error('Role not found.'); return Response::redirect('/roles'); }
        $builtIn=in_array($row['slug'],['admin','user'],true);
        if($builtIn&&!auth()->isAdmin()){Flash::error('Only an Admin can edit built-in roles.');return Response::redirect('/roles');}
        $name = $builtIn ? ucfirst((string)$row['slug']) : $request->str('name');
        $v = Validator::make(['name' => $name], ['name' => 'required|max:80']);
        if ($v->fails()) { Flash::error($v->firstError()); return Response::redirect('/roles'); }
        if ($this->db()->scalar('SELECT COUNT(*) FROM roles WHERE tenant_id = ? AND id <> ? AND LOWER(name) = LOWER(?)',
            [(int) $row['tenant_id'], (int) $row['id'], $name])) {
            Flash::error('A role with that name already exists in this tenant.');
            return Response::redirect('/roles');
        }
        $permissions = $this->permissions($request);
        $this->db()->update('roles', (int) $row['id'], [
            'name' => $name, 'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit('role.update', 'role', (int) $row['id'], ['permissions' => $permissions]);
        Flash::success('Role updated.');
        return Response::redirect('/roles');
    }

    public function destroy(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) { return Response::redirect('/roles'); }
        $assigned = (int) $this->db()->scalar(
            'SELECT COUNT(*) FROM users WHERE tenant_id = ? AND role = ?', [(int) $row['tenant_id'], $row['slug']]
        );
        if ($assigned > 0) {
            Flash::error('Move users to another role before deleting this role.');
            return Response::redirect('/roles');
        }
        $this->db()->delete('roles', (int) $row['id']);
        $this->audit('role.delete', 'role', (int) $row['id'], ['name' => $row['name']]);
        Flash::success('Role deleted.');
        return Response::redirect('/roles');
    }

    private function permissions(Request $request): array
    {
        $requested = $request->input('permissions', []);
        if (!is_array($requested)) { return []; }
        return array_values(array_intersect(array_keys(Auth::assignableAbilities()), array_map('strval', $requested)));
    }

    private function findScoped(int $id): ?array
    {
        $tid = $this->tenantId();
        return $tid ? $this->db()->one('SELECT * FROM roles WHERE id = ? AND tenant_id = ?', [$id, $tid]) : null;
    }

}
