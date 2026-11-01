<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Support\Str;

/**
 * Tenants (businesses / domains). Superadmin-only.
 *
 * Creating a tenant generates a unique Asterisk `context`, which namespaces
 * every extension, IVR, queue etc. so multiple businesses can share one
 * Asterisk without number collisions.
 */
final class TenantController extends BaseController
{
    /** GET /tenants */
    public function index(Request $request): Response
    {
        $rows = $this->db()->all('SELECT * FROM tenants ORDER BY name');

        foreach ($rows as &$t) {
            $t['counts'] = [
                'extensions' => $this->db()->count('extensions', 'tenant_id = ?', [$t['id']]),
                'users'      => $this->db()->count('users', 'tenant_id = ?', [$t['id']]),
                'trunks'     => $this->db()->count('trunks', 'tenant_id = ? AND enabled = 1', [$t['id']]),
            ];
        }
        unset($t);

        return $this->view('tenants/index', ['rows' => $rows]);
    }

    /** POST /tenants */
    public function store(Request $request): Response
    {
        $context = $this->makeContext($request->str('name'));

        $data = [
            'name'          => $request->str('name'),
            'context'       => $context,
            'domain'        => $request->str('domain'),
            'contact_email' => $request->str('contact_email'),
            'contact_phone' => $request->str('contact_phone'),
            'timezone'      => $request->str('timezone', 'America/New_York'),
            'notes'         => $request->str('notes'),
            'enabled'       => $request->bool('enabled', true) ? 1 : 0,
        ];

        $v = Validator::make($data, [
            'name'    => 'required|max:120',
            'context' => 'required|alpha_dash|max:60|unique:tenants,context',
            'domain'  => 'nullable|max:160',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/tenants');
        }

        $id = $this->db()->insert('tenants', $data);

        $this->clearOld();
        $this->audit('tenant.create', 'tenant', $id, ['name' => $data['name'], 'context' => $context]);
        $this->applyToAsterisk('tenant');

        Flash::success("Tenant \"{$data['name']}\" created (context: {$context}).");
        return Response::redirect('/tenants');
    }

    /** POST /tenants/{id} */
    public function update(Request $request): Response
    {
        $row = $this->db()->one('SELECT * FROM tenants WHERE id = ?', [(int) $request->params['id']]);
        if (!$row) {
            return Response::redirect('/tenants');
        }

        $data = [
            'name'          => $request->str('name', $row['name']),
            'domain'        => $request->str('domain'),
            'contact_email' => $request->str('contact_email'),
            'contact_phone' => $request->str('contact_phone'),
            'timezone'      => $request->str('timezone', $row['timezone']),
            'notes'         => $request->str('notes'),
            'enabled'       => $request->bool('enabled', true) ? 1 : 0,
        ];

        $v = Validator::make($data, ['name' => 'required|max:120']);
        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/tenants');
        }

        // The context is immutable after creation — renaming it would orphan
        // every dialplan reference. Show it read-only in the form.
        $this->db()->update('tenants', (int) $row['id'], $data);

        $this->audit('tenant.update', 'tenant', (int) $row['id']);
        $this->applyToAsterisk('tenant');

        Flash::success('Tenant updated.');
        return Response::redirect('/tenants');
    }

    /** DELETE /tenants/{id} */
    public function destroy(Request $request): Response
    {
        $row = $this->db()->one('SELECT * FROM tenants WHERE id = ?', [(int) $request->params['id']]);
        if (!$row) {
            return Response::redirect('/tenants');
        }

        // Guard against deleting the last tenant.
        $count = $this->db()->count('tenants');
        if ($count <= 1) {
            Flash::error('You cannot delete the last remaining tenant.');
            return Response::redirect('/tenants');
        }

        $this->db()->delete('tenants', (int) $row['id']);
        $this->audit('tenant.delete', 'tenant', (int) $row['id'], ['name' => $row['name']]);
        $this->applyToAsterisk('tenant');

        Flash::success('Tenant deleted.');
        return Response::redirect('/tenants');
    }

    /** Derive a unique, lowercase Asterisk context from a business name. */
    private function makeContext(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'tenant';
        }

        $context = $base;
        $i = 2;
        while ($this->db()->scalar('SELECT COUNT(*) FROM tenants WHERE context = ?', [$context])) {
            $context = $base . '-' . $i++;
        }

        return $context;
    }
}
