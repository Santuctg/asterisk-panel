<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Call center: queues + their agent members.
 */
final class CallCenterController extends BaseController
{
    /** GET /call-center */
    public function index(Request $request): Response
    {
        $tid  = $this->requireTenant();
        $rows = $this->db()->all(
            'SELECT q.*, a.name AS announcement_name FROM queues q
             LEFT JOIN announcements a ON a.id = q.announcement_id AND a.tenant_id = q.tenant_id
             WHERE q.tenant_id = ? ORDER BY q.extension',
            [$tid]
        );

        foreach ($rows as &$q) {
            $q['agents'] = $this->db()->all(
                'SELECT qa.*, e.extension, e.name FROM queue_agents qa
                 JOIN extensions e ON e.id = qa.extension_id
                 WHERE qa.queue_id = ? ORDER BY qa.penalty, e.extension',
                [(int) $q['id']]
            );
        }
        unset($q);

        // Live queue state (best-effort).
        $live = [];
        try {
            $live = asterisk()->queueStatus();
        } catch (\Throwable) {
            $live = [];
        }

        return $this->view('callcenter/index', [
            'rows'       => $rows,
            'live'       => $live,
            'extensions' => $this->extensionOptions(),
            'mohClasses' => $this->db()->all('SELECT name FROM moh_classes ORDER BY name'),
            'announcements' => $this->db()->all('SELECT id, name FROM announcements WHERE tenant_id = ? AND enabled = 1 ORDER BY name', [$tid]),
        ]);
    }

    /** POST /call-center */
    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();

        $data = $this->collect($request, $tid);
        $data['tenant_id'] = $tid;

        $v = Validator::make($data, [
            'extension' => 'required|digits_between:2,6',
            'name'      => 'required|max:120',
            'strategy'  => 'required|in:ringall,leastrecent,fewest,random,rrmemory,linear',
            'timeout'   => 'required|integer',
            'max_wait'  => 'required|integer',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/call-center');
        }

        $dup = $this->db()->scalar(
            'SELECT COUNT(*) FROM queues WHERE tenant_id = ? AND extension = ?',
            [$tid, $data['extension']]
        );
        if ($dup) {
            $this->stashOld($request);
            Flash::error('That extension number is already used by a queue.');
            return Response::redirect('/call-center');
        }

        $id = $this->db()->insert('queues', $data);
        $this->syncAgentsFor($id, $request);

        $this->clearOld();
        $this->audit('queue.create', 'queue', $id);
        $this->applyToAsterisk('queue');

        Flash::success('Queue created.');
        return Response::redirect('/call-center');
    }

    /** POST /call-center/{id} */
    public function update(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/call-center');
        }

        $data = $this->collect($request, (int) $row['tenant_id']);

        $v = Validator::make($data, [
            'extension' => 'required|digits_between:2,6',
            'name'      => 'required|max:120',
            'timeout'   => 'required|integer',
            'max_wait'  => 'required|integer',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/call-center');
        }

        $this->db()->update('queues', (int) $row['id'], $data);
        $this->syncAgentsFor((int) $row['id'], $request);

        $this->clearOld();
        $this->audit('queue.update', 'queue', (int) $row['id']);
        $this->applyToAsterisk('queue');

        Flash::success('Queue updated.');
        return Response::redirect('/call-center');
    }

    /** DELETE /call-center/{id} */
    public function destroy(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/call-center');
        }

        $this->db()->delete('queue_agents', (int) $row['id'], 'queue_id');
        $this->db()->delete('queues', (int) $row['id']);

        $this->audit('queue.delete', 'queue', (int) $row['id']);
        $this->applyToAsterisk('queue');

        Flash::success('Queue deleted.');
        return Response::redirect('/call-center');
    }

    /** POST /call-center/{id}/agents — standalone agent sync. */
    public function syncAgents(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/call-center');
        }

        $this->syncAgentsFor((int) $row['id'], $request);
        $this->audit('queue.agents.sync', 'queue', (int) $row['id']);
        $this->applyToAsterisk('queue');

        Flash::success('Queue agents updated.');
        return Response::redirect('/call-center');
    }

    private function syncAgentsFor(int $queueId, Request $request): void
    {
        $this->db()->delete('queue_agents', $queueId, 'queue_id');

        $ids     = (array) $request->input('agents', []);
        $penalty = (array) $request->input('penalty', []);

        // Ignore extension IDs outside this tenant so a stale form can't
        // trigger a foreign-key error.
        $tid = $this->tenantId();

        foreach (array_values($ids) as $i => $extId) {
            $extId = (int) $extId;
            if ($extId <= 0) {
                continue;
            }
            $exists = $this->db()->scalar(
                'SELECT COUNT(*) FROM extensions WHERE id = ? AND tenant_id = ?',
                [$extId, $tid]
            );
            if (!$exists) {
                continue;
            }

            $this->db()->insert('queue_agents', [
                'queue_id'     => $queueId,
                'extension_id' => $extId,
                'penalty'      => (int) ($penalty[$i] ?? 0),
                'paused'       => 0,
            ]);
        }
    }

    private function collect(Request $request, int $tenantId): array
    {
        return [
            'extension'      => $request->str('extension'),
            'name'           => $request->str('name'),
            'strategy'       => $request->str('strategy', 'ringall'),
            'timeout'        => $request->int('timeout', 15),
            'max_wait'       => $request->int('max_wait', 300),
            'service_level'  => $request->int('service_level', 60),
            'wrapup'         => $request->int('wrapup', 5),
            'retry'          => $request->int('retry', 5),
            'member_delay'   => $request->int('member_delay', 0),
            'agent_announce' => $request->str('agent_announce'),
            'moh_class'      => $request->str('moh_class', 'default'),
            'announcement_id'=> $this->validAnnouncementId($request->int('announcement_id'), $tenantId),
            'enabled'        => $request->bool('enabled', true) ? 1 : 0,
        ];
    }

    private function findScoped(int $id): ?array
    {
        $tid = $this->tenantId();
        if (!$tid) {
            return null;
        }
        if (auth()->isSuperAdmin()) {
            return $this->db()->one('SELECT * FROM queues WHERE id = ?', [$id]);
        }
        return $this->db()->one('SELECT * FROM queues WHERE id = ? AND tenant_id = ?', [$id, $tid]);
    }

    private function validAnnouncementId(int $id, int $tenantId): ?int
    {
        if ($id <= 0) { return null; }
        return $this->db()->scalar('SELECT COUNT(*) FROM announcements WHERE id = ? AND tenant_id = ? AND enabled = 1', [$id, $tenantId])
            ? $id : null;
    }

    private function extensionOptions(): array
    {
        $tid = $this->tenantId();
        return $this->db()->all(
            'SELECT id, extension, name FROM extensions WHERE tenant_id = ? AND enabled = 1 ORDER BY extension',
            [$tid]
        );
    }
}
