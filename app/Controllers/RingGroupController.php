<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Ring groups: a list of extensions that ring together.
 * Strategy maps to Asterisk's Dial() options / group_call semantics.
 */
final class RingGroupController extends BaseController
{
    /** GET /ring-groups */
    public function index(Request $request): Response
    {
        $tid  = $this->requireTenant();
        $rows = $this->db()->all(
            'SELECT * FROM ring_groups WHERE tenant_id = ? ORDER BY extension',
            [$tid]
        );

        // Attach member extension numbers for display.
        foreach ($rows as &$r) {
            $r['members'] = $this->db()->all(
                'SELECT e.extension, e.name FROM ring_group_members m
                 JOIN extensions e ON e.id = m.extension_id
                 WHERE m.ring_group_id = ? ORDER BY m.ordering',
                [(int) $r['id']]
            );
        }
        unset($r);

        return $this->view('ringgroups/index', ['rows' => $rows]);
    }

    /** GET /ring-groups/create */
    public function create(Request $request): Response
    {
        return $this->view('ringgroups/form', [
            'row'        => null,
            'extensions' => $this->extensionOptions(),
            'next'       => $this->suggestNext(),
        ]);
    }

    /** POST /ring-groups */
    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();

        $data = [
            'extension' => $request->str('extension'),
            'name'      => $request->str('name'),
            'strategy'  => $request->str('strategy', 'ringall'),
            'timeout'   => $request->int('timeout', 30),
            'ringback'  => $request->str('ringback'),
            'enabled'   => $request->bool('enabled', true) ? 1 : 0,
            'tenant_id' => $tid,
        ];

        $v = Validator::make($data, [
            'extension' => 'required|digits_between:2,6',
            'name'      => 'required|max:120',
            'strategy'  => 'required|in:ringall,leastrecent,random,wrandom,seqfixed',
            'timeout'   => 'required|integer',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/ring-groups/create');
        }

        $dup = $this->db()->scalar(
            'SELECT COUNT(*) FROM ring_groups WHERE tenant_id = ? AND extension = ?',
            [$tid, $data['extension']]
        );
        if ($dup) {
            $this->stashOld($request);
            Flash::error('That extension number is already used by a ring group.');
            return Response::redirect('/ring-groups/create');
        }

        $id = $this->db()->insert('ring_groups', $data);
        $this->syncMembers($id, $request);

        $this->clearOld();
        $this->audit('ringgroup.create', 'ring_group', $id);
        $this->applyToAsterisk('ringgroup');

        Flash::success('Ring group created.');
        return Response::redirect('/ring-groups');
    }

    /** GET /ring-groups/{id}/edit */
    public function edit(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/ring-groups');
        }

        $row['members'] = $this->db()->all(
            'SELECT extension_id FROM ring_group_members WHERE ring_group_id = ?',
            [(int) $row['id']]
        );

        return $this->view('ringgroups/form', [
            'row'        => $row,
            'extensions' => $this->extensionOptions(),
            'next'       => [],
        ]);
    }

    /** POST /ring-groups/{id} */
    public function update(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/ring-groups');
        }

        $data = [
            'extension' => $request->str('extension'),
            'name'      => $request->str('name'),
            'strategy'  => $request->str('strategy', 'ringall'),
            'timeout'   => $request->int('timeout', 30),
            'ringback'  => $request->str('ringback'),
            'enabled'   => $request->bool('enabled', true) ? 1 : 0,
        ];

        $v = Validator::make($data, [
            'extension' => 'required|digits_between:2,6',
            'name'      => 'required|max:120',
            'timeout'   => 'required|integer',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect("/ring-groups/{$row['id']}/edit");
        }

        $this->db()->update('ring_groups', (int) $row['id'], $data);
        $this->syncMembers((int) $row['id'], $request);

        $this->clearOld();
        $this->audit('ringgroup.update', 'ring_group', (int) $row['id']);
        $this->applyToAsterisk('ringgroup');

        Flash::success('Ring group updated.');
        return Response::redirect('/ring-groups');
    }

    /** DELETE /ring-groups/{id} */
    public function destroy(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/ring-groups');
        }

        $this->db()->delete('ring_group_members', (int) $row['id'], 'ring_group_id');
        $this->db()->delete('ring_groups', (int) $row['id']);

        $this->audit('ringgroup.delete', 'ring_group', (int) $row['id']);
        $this->applyToAsterisk('ringgroup');

        Flash::success('Ring group deleted.');
        return Response::redirect('/ring-groups');
    }

    private function syncMembers(int $groupId, Request $request): void
    {
        $this->db()->delete('ring_group_members', $groupId, 'ring_group_id');

        $memberIds = (array) $request->input('members', []);

        // Only accept extension IDs that actually exist in this tenant so a
        // stale form can't blow up on the foreign key.
        $tid = $this->tenantId();
        $valid = [];
        foreach ($memberIds as $extId) {
            $extId = (int) $extId;
            if ($extId <= 0) {
                continue;
            }
            $exists = $this->db()->scalar(
                'SELECT COUNT(*) FROM extensions WHERE id = ? AND tenant_id = ?',
                [$extId, $tid]
            );
            if ($exists) {
                $valid[] = $extId;
            }
        }

        foreach (array_values($valid) as $i => $extId) {
            $this->db()->insert('ring_group_members', [
                'ring_group_id' => $groupId,
                'extension_id'  => $extId,
                'ordering'      => $i,
            ]);
        }
    }

    private function findScoped(int $id): ?array
    {
        $tid = $this->tenantId();
        if (!$tid) {
            return null;
        }
        if (auth()->isSuperAdmin()) {
            return $this->db()->one('SELECT * FROM ring_groups WHERE id = ?', [$id]);
        }
        return $this->db()->one('SELECT * FROM ring_groups WHERE id = ? AND tenant_id = ?', [$id, $tid]);
    }

    private function extensionOptions(): array
    {
        $tid = $this->tenantId();
        return $this->db()->all(
            'SELECT id, extension, name FROM extensions WHERE tenant_id = ? AND enabled = 1 ORDER BY extension',
            [$tid]
        );
    }

    private function suggestNext(): array
    {
        $tid = $this->tenantId();
        for ($n = 200; $n <= 299; $n++) {
            $used = $this->db()->scalar(
                'SELECT COUNT(*) FROM ring_groups WHERE tenant_id = ? AND extension = ?',
                [$tid, (string) $n]
            );
            if (!$used) {
                return ['extension' => (string) $n];
            }
        }
        return ['extension' => ''];
    }
}
