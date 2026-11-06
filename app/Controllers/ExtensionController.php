<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Extensions (PJSIP endpoints). The core building block every other feature
 * references — voicemail boxes, ring groups, queues, devices all point here.
 */
final class ExtensionController extends BaseController
{
    private const FILLABLE = [
        'extension', 'name', 'secret', 'cid_name', 'cid_number',
        'vm_enabled', 'vm_on_busy', 'vm_on_noanswer', 'ring_timeout',
        'max_registrations', 'follow_me', 'enabled', 'description', 'record',
        'outbound_route_id',
    ];

    /** GET /extensions */
    public function index(Request $request): Response
    {
        $tid   = $this->requireTenant();
        $q     = $request->str('q');
        $page  = $request->page();
        $perPage = $request->perPage();

        $where  = 'e.tenant_id = ?';
        $params = [$tid];

        if ($q !== '') {
            $where .= ' AND (e.extension LIKE :q OR e.name LIKE :q OR e.cid_name LIKE :q)';
            $params[] = $q;
        }

        $db     = $this->db();
        $total  = (int) $db->scalar("SELECT COUNT(*) FROM extensions e WHERE {$where}", $params);
        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        $rows = $db->all(
            "SELECT e.*, v.id AS vm_id, v.email AS vm_email, v.attach AS vm_attach,
                    d.mac AS device_mac, d.vendor AS device_vendor,
                    o.name AS outbound_route_name
             FROM extensions e
             LEFT JOIN voicemail v ON v.extension_id = e.id
             LEFT JOIN devices d ON d.extension_id = e.id
             LEFT JOIN outbound_routes o ON o.id = e.outbound_route_id AND o.tenant_id = e.tenant_id
             WHERE {$where}
             ORDER BY e.extension
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $registrationAvailable = false;
        $contactDetails = [];
        try {
            $mgr = asterisk();
            $registrationAvailable = $mgr->isOnline();
            if ($registrationAvailable) { $contactDetails = $mgr->contactDetails(); }
        } catch (\Throwable) {
            $registrationAvailable = false;
        }
        $tenant = $db->one('SELECT context, domain FROM tenants WHERE id = ?', [$tid]) ?? [];
        $context = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) ($tenant['context'] ?? '')) ?: '';
        $realm = strtolower(rtrim(trim((string) ($tenant['domain'] ?? '')), '.')) ?: (string) ($tenant['context'] ?? '');

        // Live state for every extension in this tenant (list + monitor card).
        $allExtensions = $db->all(
            'SELECT id, extension, name, enabled FROM extensions WHERE tenant_id = ? ORDER BY extension',
            [$tid]
        );
        $live = [];
        foreach ($allExtensions as $ae) {
            $keys = [
                $ae['extension'] . '@' . $realm,
                $context . '_' . $ae['extension'] . '-phone-web',
                $context . '_' . $ae['extension'] . '-web',
            ];
            $state = ['status' => null, 'rtt' => null, 'devices' => 0, 'ips' => []];
            foreach ($keys as $key) {
                if (!isset($contactDetails[$key])) {
                    continue;
                }
                $state['status'] ??= $contactDetails[$key]['status'];
                $state['rtt']    ??= $contactDetails[$key]['rtt'];
                $state['devices'] += $contactDetails[$key]['count'];
                foreach (($contactDetails[$key]['ips'] ?? []) as $ip) {
                    if (!in_array($ip, $state['ips'], true)) {
                        $state['ips'][] = $ip;
                    }
                }
            }
            $live[(int) $ae['id']] = $state;
        }

        foreach ($rows as &$row) {
            $row['sip_username'] = $row['extension'];
            $row['sip_realm'] = $realm;
            $state = $live[(int) $row['id']] ?? ['status' => null, 'rtt' => null, 'devices' => 0, 'ips' => []];
            $row['registration_status'] = $state['status'];
            $row['latency_ms'] = $state['rtt'];
            $row['active_devices'] = $state['devices'];
            $row['registration_ips'] = $state['ips'];
        }
        unset($row);

        // Current online duration per extension on this page.
        $onlineSince = [];
        $pageIds = array_map('intval', array_column($rows, 'id'));
        if ($pageIds !== []) {
            $ph = implode(',', array_fill(0, count($pageIds), '?'));
            foreach ($db->all(
                "SELECT extension_id, started_at FROM extension_status_history
                 WHERE extension_id IN ({$ph}) AND ended_at IS NULL AND status = 'Registered'",
                $pageIds
            ) as $h) {
                $onlineSince[(int) $h['extension_id']] = (string) $h['started_at'];
            }
        }
        foreach ($rows as &$row) {
            $since = $onlineSince[(int) $row['id']] ?? null;
            $row['online_since'] = $since;
            $row['online_seconds'] = $since ? max(0, time() - (strtotime($since) ?: time())) : null;
        }
        unset($row);

        // 24h availability timeline + event history for the monitor cards.
        $windowStart = date('Y-m-d H:i:s', time() - 86400);
        $timeline = [];
        foreach ($allExtensions as $ae) {
            $id = (int) $ae['id'];
            $timeline[$id] = [
                'id' => $id,
                'extension' => (string) $ae['extension'],
                'name' => (string) $ae['name'],
                'enabled' => (int) $ae['enabled'],
                'state' => $live[$id] ?? ['status' => null, 'rtt' => null, 'devices' => 0, 'ips' => []],
                'events' => [],
            ];
        }
        foreach ($db->all(
            'SELECT h.extension_id, h.status, h.started_at, h.ended_at
             FROM extension_status_history h
             WHERE h.tenant_id = ? AND (h.ended_at IS NULL OR h.ended_at >= ?)
             ORDER BY h.started_at, h.id',
            [$tid, $windowStart]
        ) as $h) {
            $eid = (int) $h['extension_id'];
            if (isset($timeline[$eid])) {
                $timeline[$eid]['events'][] = $h;
            }
        }

        // Attach monitor details to each visible row so its Monitor button can
        // open an item-specific popup instead of rendering global cards.
        foreach ($rows as &$row) {
            $id = (int) $row['id'];
            $row['timeline_events'] = $timeline[$id]['events'] ?? [];
            $row['monitor_history'] = $db->all(
                'SELECT status, started_at, ended_at
                 FROM extension_status_history
                 WHERE tenant_id = ? AND extension_id = ?
                 ORDER BY started_at DESC, id DESC LIMIT 50',
                [$tid, $id]
            );
        }
        unset($row);

        return $this->view('extensions/index', [
            'rows' => $rows, 'q' => $q, 'page' => $page, 'pages' => $pages, 'total' => $total,
            'registrationAvailable' => $registrationAvailable,
        ]);
    }

    /** GET /extensions/create */
    public function create(Request $request): Response
    {
        $defaults = $this->suggestNext();

        return $this->view('extensions/form', [
            'row'       => null,
            'defaults'  => $defaults,
            'tenants'   => $this->tenantOptions(),
            'outboundRoutes' => $this->outboundRouteOptions($this->tenantId()),
        ]);
    }

    /** POST /extensions */
    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();
        if (!$tid) {
            return Response::redirect('/extensions');
        }

        $data = $this->collect($request, $tid);
        // The unique validator needs the tenant scope before it runs. Without
        // this, an extension number used by another tenant is rejected.
        $data['tenant_id'] = $tid;

        $v = Validator::make($data, [
            'extension' => 'required|digits_between:2,6|unique:extensions,extension,,tenant_id',
            'name'      => 'required|max:120',
            'secret'    => 'required',
            'cid_number'=> 'nullable|digits_between:3,16',
            'ring_timeout' => 'nullable|integer',
        ]);
        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/extensions/create');
        }

        // Unique check scoped by tenant (Validator's unique rule is generic).
        $dup = $this->db()->scalar(
            'SELECT COUNT(*) FROM extensions WHERE tenant_id = ? AND extension = ?',
            [$tid, $data['extension']]
        );
        if ($dup) {
            $this->stashOld($request);
            Flash::error('That extension number is already in use in this tenant.');
            return Response::redirect('/extensions/create');
        }

        $id = $this->db()->insert('extensions', $data);

        // Auto-create a matching voicemail box if the caller asked for it.
        if (!empty($data['vm_enabled']) && $request->bool('create_voicemail')) {
            $this->db()->insert('voicemail', [
                'tenant_id'    => $tid,
                'extension_id' => $id,
                'pin'          => $request->str('vm_pin') ?: random_pin(6),
                'name'         => $data['name'],
                'email'        => $request->str('vm_email'),
                'attach'       => 1,
                'enabled'      => 1,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        $this->clearOld();
        $this->audit('extension.create', 'extension', $id, ['extension' => $data['extension']]);
        $this->applyToAsterisk('extension');

        Flash::success("Extension {$data['extension']} created.");
        return Response::redirect('/extensions');
    }

    /** GET /extensions/{id}/edit */
    public function edit(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            Flash::error('Extension not found.');
            return Response::redirect('/extensions');
        }

        return $this->view('extensions/form', [
            'row'      => $row,
            'defaults' => [],
            'tenants'  => $this->tenantOptions(),
            'outboundRoutes' => $this->outboundRouteOptions((int) $row['tenant_id']),
        ]);
    }

    /** POST /extensions/{id} */
    public function update(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            Flash::error('Extension not found.');
            return Response::redirect('/extensions');
        }

        $data = $this->collect($request, (int) $row['tenant_id']);

        $v = Validator::make($data + ['id' => $row['id']], [
            'extension' => 'required|digits_between:2,6',
            'name'      => 'required|max:120',
            'secret'    => 'required',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect("/extensions/{$row['id']}/edit");
        }

        $dup = $this->db()->scalar(
            'SELECT COUNT(*) FROM extensions WHERE tenant_id = ? AND extension = ? AND id != ?',
            [(int) $row['tenant_id'], $data['extension'], (int) $row['id']]
        );
        if ($dup) {
            $this->stashOld($request);
            Flash::error('That extension number is already in use in this tenant.');
            return Response::redirect("/extensions/{$row['id']}/edit");
        }

        $this->db()->update('extensions', (int) $row['id'], $data);

        // Keep the voicemail mailbox name/number in sync.
        $this->db()->update('voicemail', (int) $row['id'], [
            'name' => $data['name'],
        ], 'extension_id');

        $this->clearOld();
        $this->audit('extension.update', 'extension', (int) $row['id'], ['extension' => $data['extension']]);
        $this->applyToAsterisk('extension');

        Flash::success("Extension {$data['extension']} updated.");
        return Response::redirect('/extensions');
    }

    /** DELETE /extensions/{id} */
    public function destroy(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            Flash::error('Extension not found.');
            return Response::redirect('/extensions');
        }

        $this->db()->delete('extensions', (int) $row['id']);
        // Clean up dependent rows that cascade didn't cover (MySQL).
        $this->db()->delete('voicemail', (int) $row['id'], 'extension_id');
        $this->db()->delete('queue_agents', (int) $row['id'], 'extension_id');
        $this->db()->delete('ring_group_members', (int) $row['id'], 'extension_id');

        $this->audit('extension.delete', 'extension', (int) $row['id'], ['extension' => $row['extension']]);
        $this->applyToAsterisk('extension');

        Flash::success("Extension {$row['extension']} deleted.");
        return Response::redirect('/extensions');
    }

    /** POST /extensions/{id}/regenerate-secret */
    public function regenerateSecret(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/extensions');
        }

        $secret = random_secret(14);
        $this->db()->update('extensions', (int) $row['id'], ['secret' => $secret]);

        $this->audit('extension.secret.regenerate', 'extension', (int) $row['id']);
        $this->applyToAsterisk('extension');

        Flash::success("SIP password regenerated for {$row['extension']}.");
        return Response::redirect('/extensions');
    }

    // ----- helpers -------------------------------------------------------

    private function collect(Request $request, int $tid): array
    {
        return [
            'extension'         => $request->str('extension'),
            'name'              => $request->str('name'),
            'secret'            => $request->str('secret') ?: random_secret(14),
            'cid_name'          => $request->str('cid_name'),
            'cid_number'        => $request->str('cid_number'),
            'vm_enabled'        => $request->bool('vm_enabled') ? 1 : 0,
            'vm_on_busy'        => $request->bool('vm_on_busy') ? 1 : 0,
            'vm_on_noanswer'    => $request->bool('vm_on_noanswer') ? 1 : 0,
            'ring_timeout'      => $request->int('ring_timeout', 25),
            'max_registrations' => max(1, $request->int('max_registrations', 1)),
            'follow_me'         => $request->bool('follow_me') ? 1 : 0,
            'enabled'           => $request->bool('enabled', true) ? 1 : 0,
            'record'            => $request->int('record', 0),
            'outbound_route_id' => $this->validOutboundRouteId($request->int('outbound_route_id'), $tid),
            'description'       => $request->str('description'),
        ];
    }

    private function outboundRouteOptions(?int $tid): array
    {
        if (!$tid) {
            return [];
        }

        $options = [];
        foreach ($this->db()->all(
            'SELECT id, name FROM outbound_routes WHERE tenant_id = ? AND enabled = 1 ORDER BY priority, name',
            [$tid]
        ) as $route) {
            $options[(int) $route['id']] = (string) $route['name'];
        }
        return $options;
    }

    private function validOutboundRouteId(int $routeId, int $tid): ?int
    {
        if ($routeId <= 0) {
            return null;
        }

        return $this->db()->scalar(
            'SELECT id FROM outbound_routes WHERE id = ? AND tenant_id = ? AND enabled = 1',
            [$routeId, $tid]
        ) ? $routeId : null;
    }

    /** Fetch an extension, scoped to the caller's tenant (or any if super). */
    private function findScoped(int $id): ?array
    {
        $tid = $this->tenantId();
        if (!$tid) {
            return null;
        }

        if (auth()->isSuperAdmin()) {
            return $this->db()->one('SELECT * FROM extensions WHERE id = ?', [$id]);
        }

        return $this->db()->one('SELECT * FROM extensions WHERE id = ? AND tenant_id = ?', [$id, $tid]);
    }

    /** Suggest the next free extension number in the configured range. */
    private function suggestNext(): array
    {
        $tid  = $this->tenantId();
        $min  = (int) config('app.defaults.extension_range.1000', 1000);
        $max  = (int) config('app.defaults.extension_range.1', 1999);

        $used = $this->db()->all(
            'SELECT extension FROM extensions WHERE tenant_id = ?',
            [$tid]
        );
        $used = array_flip(array_map('intval', array_column($used, 'extension')));

        for ($n = $min; $n <= $max; $n++) {
            if (!isset($used[$n])) {
                return ['extension' => (string) $n, 'ring_timeout' => (int) config('app.defaults.ring_timeout', 25)];
            }
        }

        return ['extension' => '', 'ring_timeout' => 25];
    }

    private function tenantOptions(): array
    {
        if (auth()->isSuperAdmin()) {
            return $this->db()->all('SELECT id, name FROM tenants WHERE enabled = 1 ORDER BY name');
        }
        $tid = $this->tenantId();
        return $tid ? $this->db()->all('SELECT id, name FROM tenants WHERE id = ?', [$tid]) : [];
    }

}
