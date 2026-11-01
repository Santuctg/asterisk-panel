<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Call routing: inbound routes (DID -> destination), outbound routes
 * (pattern -> trunk), SIP trunks, and time conditions.
 *
 * All four live behind /routes because they answer the same question:
 * "where does this call go?"
 */
final class RouteController extends BaseController
{
    private const DEST_TYPES = [
        'extension', 'ringgroup', 'ivr', 'queue', 'conference',
        'parking', 'timecond', 'external', 'hangup',
    ];

    /** GET /routes */
    public function index(Request $request): Response
    {
        $tid = $this->requireTenant();
        $db  = $this->db();

        $inbound  = $db->all('SELECT * FROM inbound_routes WHERE tenant_id = ? ORDER BY priority, id', [$tid]);
        $outbound = $db->all(
            'SELECT o.*, t.name AS trunk_name FROM outbound_routes o
             LEFT JOIN trunks t ON t.id = o.trunk_id
             WHERE o.tenant_id = ? ORDER BY o.priority, o.id',
            [$tid]
        );
        $trunks = $db->all('SELECT * FROM trunks WHERE tenant_id = ? ORDER BY name', [$tid]);
        $times  = $db->all('SELECT * FROM time_conditions WHERE tenant_id = ? ORDER BY name', [$tid]);

        // Resolve destination labels for display.
        $resolve = function (string $type, ?int $id) use ($db): string {
            if ($id === null || $id === 0) {
                return $type === 'hangup' ? 'Hang up' : ($type === 'external' ? 'External' : '—');
            }
            $table = self::destTable($type);
            if (!$table) {
                return '—';
            }
            $r = $db->one("SELECT extension, name FROM {$table} WHERE id = ?", [$id]);
            return $r ? "{$r['extension']} — {$r['name']}" : '—';
        };

        foreach ($inbound as &$r) {
            $r['dest_label'] = $resolve($r['dest_type'], $r['dest_id'] !== null ? (int) $r['dest_id'] : null);
        }
        unset($r);

        foreach ($times as &$t) {
            $t['open_label']  = $resolve($t['open_type'], $t['open_dest_id'] !== null ? (int) $t['open_dest_id'] : null);
            $t['closed_label']= $resolve($t['closed_type'], $t['closed_dest_id'] !== null ? (int) $t['closed_dest_id'] : null);
        }
        unset($t);

        return $this->view('routes/index', [
            'inbound'     => $inbound,
            'outbound'    => $outbound,
            'trunks'      => $trunks,
            'times'       => $times,
            'destinations'=> $this->destinationOptions(),
            'destTypes'   => self::DEST_TYPES,
        ]);
    }

    // ----- inbound -------------------------------------------------------

    public function storeInbound(Request $request): Response
    {
        $tid = $this->requireTenant();

        $data = [
            'tenant_id'   => $tid,
            'name'        => $request->str('name'),
            'did_pattern' => $request->str('did_pattern'),
            'cid_pattern' => $request->str('cid_pattern'),
            'dest_type'   => $request->str('dest_type'),
            'dest_id'     => $request->int('dest_id') ?: null,
            'priority'    => $request->int('priority', 0),
            'enabled'     => $request->bool('enabled', true) ? 1 : 0,
        ];

        $v = Validator::make($data, [
            'name'        => 'required|max:120',
            'did_pattern' => 'required|max:60',
            'dest_type'   => 'required|in:' . implode(',', self::DEST_TYPES),
        ]);

        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/routes');
        }

        $id = $this->db()->insert('inbound_routes', $data);
        $this->audit('route.inbound.create', 'inbound_route', $id);
        $this->applyToAsterisk('route');

        Flash::success('Inbound route added.');
        return Response::redirect('/routes');
    }

    public function updateInbound(Request $request): Response
    {
        $row = $this->findScoped('inbound_routes', (int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/routes');
        }

        $data = [
            'name'        => $request->str('name'),
            'did_pattern' => $request->str('did_pattern'),
            'cid_pattern' => $request->str('cid_pattern'),
            'dest_type'   => $request->str('dest_type'),
            'dest_id'     => $request->int('dest_id') ?: null,
            'priority'    => $request->int('priority', 0),
            'enabled'     => $request->bool('enabled', true) ? 1 : 0,
        ];

        $v = Validator::make($data, [
            'name'        => 'required|max:120',
            'did_pattern' => 'required|max:60',
            'dest_type'   => 'required|in:' . implode(',', self::DEST_TYPES),
        ]);
        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/routes');
        }

        $this->db()->update('inbound_routes', (int) $row['id'], $data);
        $this->audit('route.inbound.update', 'inbound_route', (int) $row['id']);
        $this->applyToAsterisk('route');

        Flash::success('Inbound route updated.');
        return Response::redirect('/routes');
    }

    public function destroyInbound(Request $request): Response
    {
        $row = $this->findScoped('inbound_routes', (int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/routes');
        }

        $this->db()->delete('inbound_routes', (int) $row['id']);
        $this->audit('route.inbound.delete', 'inbound_route', (int) $row['id']);
        $this->applyToAsterisk('route');

        Flash::success('Inbound route deleted.');
        return Response::redirect('/routes');
    }

    // ----- outbound ------------------------------------------------------

    public function storeOutbound(Request $request): Response
    {
        $tid = $this->requireTenant();

        $data = [
            'tenant_id'     => $tid,
            'name'          => $request->str('name'),
            'prefix'        => $request->str('prefix'),
            'match_pattern' => $request->str('match_pattern'),
            'trunk_id'      => $request->int('trunk_id') ?: null,
            'cid_override'  => $request->str('cid_override'),
            'priority'      => $request->int('priority', 0),
            'enabled'       => $request->bool('enabled', true) ? 1 : 0,
        ];

        $v = Validator::make($data, [
            'name'          => 'required|max:120',
            'match_pattern' => 'required|max:60',
            'trunk_id'      => 'required|integer',
        ]);

        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/routes');
        }

        $id = $this->db()->insert('outbound_routes', $data);
        $this->audit('route.outbound.create', 'outbound_route', $id);
        $this->applyToAsterisk('route');

        Flash::success('Outbound route added.');
        return Response::redirect('/routes');
    }

    public function updateOutbound(Request $request): Response
    {
        $row = $this->findScoped('outbound_routes', (int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/routes');
        }

        $data = [
            'name'          => $request->str('name'),
            'prefix'        => $request->str('prefix'),
            'match_pattern' => $request->str('match_pattern'),
            'trunk_id'      => $request->int('trunk_id') ?: null,
            'cid_override'  => $request->str('cid_override'),
            'priority'      => $request->int('priority', 0),
            'enabled'       => $request->bool('enabled', true) ? 1 : 0,
        ];

        $v = Validator::make($data, [
            'name'          => 'required|max:120',
            'match_pattern' => 'required|max:60',
            'trunk_id'      => 'required|integer',
        ]);
        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/routes');
        }

        $this->db()->update('outbound_routes', (int) $row['id'], $data);
        $this->audit('route.outbound.update', 'outbound_route', (int) $row['id']);
        $this->applyToAsterisk('route');

        Flash::success('Outbound route updated.');
        return Response::redirect('/routes');
    }

    public function destroyOutbound(Request $request): Response
    {
        $row = $this->findScoped('outbound_routes', (int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/routes');
        }

        $this->db()->delete('outbound_routes', (int) $row['id']);
        $this->audit('route.outbound.delete', 'outbound_route', (int) $row['id']);
        $this->applyToAsterisk('route');

        Flash::success('Outbound route deleted.');
        return Response::redirect('/routes');
    }

    // ----- trunks --------------------------------------------------------

    public function storeTrunk(Request $request): Response
    {
        $tid = $this->requireTenant();

        $data = [
            'tenant_id'   => $tid,
            'name'        => $request->str('name'),
            'host'        => $request->str('host'),
            'username'    => $request->str('username'),
            'secret'      => $request->str('secret'),
            'from_domain' => $request->str('from_domain'),
            'cid'         => $request->str('cid'),
            'register'    => $request->int('register', 300),
            'enabled'     => $request->bool('enabled', true) ? 1 : 0,
        ];

        $v = Validator::make($data, [
            'name' => 'required|max:120',
            'host' => 'required|max:160',
        ]);

        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/routes');
        }

        $id = $this->db()->insert('trunks', $data);
        $this->audit('route.trunk.create', 'trunk', $id);
        $this->applyToAsterisk('trunk');

        Flash::success('Trunk added.');
        return Response::redirect('/routes');
    }

    public function updateTrunk(Request $request): Response
    {
        $row = $this->findScoped('trunks', (int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/routes');
        }

        $data = [
            'name'        => $request->str('name'),
            'host'        => $request->str('host'),
            'username'    => $request->str('username'),
            'secret'      => $request->str('secret'),
            'from_domain' => $request->str('from_domain'),
            'cid'         => $request->str('cid'),
            'register'    => $request->int('register', 300),
            'enabled'     => $request->bool('enabled', true) ? 1 : 0,
        ];

        // Allow leaving the secret blank to keep the existing one.
        if ($data['secret'] === '') {
            unset($data['secret']);
        }

        $this->db()->update('trunks', (int) $row['id'], $data);
        $this->audit('route.trunk.update', 'trunk', (int) $row['id']);
        $this->applyToAsterisk('trunk');

        Flash::success('Trunk updated.');
        return Response::redirect('/routes');
    }

    public function destroyTrunk(Request $request): Response
    {
        $row = $this->findScoped('trunks', (int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/routes');
        }

        $this->db()->delete('trunks', (int) $row['id']);
        $this->audit('route.trunk.delete', 'trunk', (int) $row['id']);
        $this->applyToAsterisk('trunk');

        Flash::success('Trunk deleted.');
        return Response::redirect('/routes');
    }

    // ----- time conditions -----------------------------------------------

    public function storeTime(Request $request): Response
    {
        $tid = $this->requireTenant();

        $data = [
            'tenant_id'      => $tid,
            'extension'      => $request->str('extension'),
            'name'           => $request->str('name'),
            'days_of_week'   => $request->str('days_of_week', '*'),
            'day_of_month'   => $request->str('day_of_month', '*'),
            'month'          => $request->str('month', '*'),
            'start_hour'     => $request->int('start_hour') ?: null,
            'start_minute'   => $request->int('start_minute', 0),
            'end_hour'       => $request->int('end_hour') ?: null,
            'end_minute'     => $request->int('end_minute', 0),
            'time_match'     => $request->str('time_match'),
            'open_type'      => $request->str('open_type'),
            'open_dest_id'   => $request->int('open_dest_id') ?: null,
            'closed_type'    => $request->str('closed_type'),
            'closed_dest_id' => $request->int('closed_dest_id') ?: null,
            'priority'       => $request->int('priority', 0),
            'enabled'        => $request->bool('enabled', true) ? 1 : 0,
        ];

        $v = Validator::make($data, ['name' => 'required|max:120']);
        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/routes');
        }

        $id = $this->db()->insert('time_conditions', $data);
        $this->audit('route.time.create', 'time_condition', $id);
        $this->applyToAsterisk('timecondition');

        Flash::success('Time condition added.');
        return Response::redirect('/routes');
    }

    public function updateTime(Request $request): Response
    {
        $row = $this->findScoped('time_conditions', (int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/routes');
        }

        $data = [
            'extension'      => $request->str('extension'),
            'name'           => $request->str('name'),
            'days_of_week'   => $request->str('days_of_week', '*'),
            'day_of_month'   => $request->str('day_of_month', '*'),
            'month'          => $request->str('month', '*'),
            'start_hour'     => $request->int('start_hour') ?: null,
            'start_minute'   => $request->int('start_minute', 0),
            'end_hour'       => $request->int('end_hour') ?: null,
            'end_minute'     => $request->int('end_minute', 0),
            'time_match'     => $request->str('time_match'),
            'open_type'      => $request->str('open_type'),
            'open_dest_id'   => $request->int('open_dest_id') ?: null,
            'closed_type'    => $request->str('closed_type'),
            'closed_dest_id' => $request->int('closed_dest_id') ?: null,
            'priority'       => $request->int('priority', 0),
            'enabled'        => $request->bool('enabled', true) ? 1 : 0,
        ];

        $this->db()->update('time_conditions', (int) $row['id'], $data);
        $this->audit('route.time.update', 'time_condition', (int) $row['id']);
        $this->applyToAsterisk('timecondition');

        Flash::success('Time condition updated.');
        return Response::redirect('/routes');
    }

    public function destroyTime(Request $request): Response
    {
        $row = $this->findScoped('time_conditions', (int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/routes');
        }

        $this->db()->delete('time_conditions', (int) $row['id']);
        $this->audit('route.time.delete', 'time_condition', (int) $row['id']);
        $this->applyToAsterisk('timecondition');

        Flash::success('Time condition deleted.');
        return Response::redirect('/routes');
    }

    // ----- shared helpers ------------------------------------------------

    private function findScoped(string $table, int $id): ?array
    {
        $tid = $this->tenantId();
        if (!$tid) {
            return null;
        }
        if (auth()->isSuperAdmin()) {
            return $this->db()->one("SELECT * FROM {$table} WHERE id = ?", [$id]);
        }
        return $this->db()->one("SELECT * FROM {$table} WHERE id = ? AND tenant_id = ?", [$id, $tid]);
    }

    private static function destTable(string $type): ?string
    {
        return match ($type) {
            'extension'  => 'extensions',
            'ringgroup'  => 'ring_groups',
            'ivr'        => 'ivr_menus',
            'queue'      => 'queues',
            'conference' => 'conferences',
            'parking'    => 'parking_lots',
            'timecond'   => 'time_conditions',
            default      => null,
        };
    }

    /** Options for destination pickers, grouped by type. */
    public function destinationOptions(): array
    {
        $tid  = $this->tenantId();
        $db   = $this->db();
        $opts = [];

        foreach (self::DEST_TYPES as $type) {
            $table = self::destTable($type);
            if (!$table) {
                continue;
            }
            foreach ($db->all("SELECT id, extension, name FROM {$table} WHERE tenant_id = ? AND enabled = 1 ORDER BY extension", [$tid]) as $r) {
                $opts[$type][] = ['id' => $r['id'], 'label' => "{$r['extension']} — {$r['name']}"];
            }
        }

        return $opts;
    }
}
