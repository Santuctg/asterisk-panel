<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Asterisk\TrunkStatusTracker;

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
        'parking', 'timecond', 'announcement', 'external', 'hangup',
    ];

    /** GET /routes */
    public function index(Request $request): Response
    {
        $tid = $this->requireTenant();
        $db  = $this->db();

        $inbound  = $db->all(
            'SELECT r.*, a.name AS announcement_name FROM inbound_routes r
             LEFT JOIN announcements a ON a.id = r.announcement_id AND a.tenant_id = r.tenant_id
             WHERE r.tenant_id = ? ORDER BY r.priority, r.id',
            [$tid]
        );
        $outbound = $db->all(
            'SELECT o.*, t.name AS trunk_name, tc.name AS time_condition_name, tc.enabled AS time_condition_enabled FROM outbound_routes o
             LEFT JOIN trunks t ON t.id = o.trunk_id
             LEFT JOIN time_conditions tc ON tc.id = o.time_condition_id AND tc.tenant_id = o.tenant_id
             WHERE o.tenant_id = ? ORDER BY o.priority, o.id',
            [$tid]
        );
        foreach ($outbound as &$route) {
            $route['schedules'] = $db->all(
                'SELECT id, name, days_of_week, start_time, end_time, ordering
                 FROM outbound_route_schedules WHERE tenant_id = ? AND outbound_route_id = ? ORDER BY ordering, id',
                [$tid, (int) $route['id']]
            );
        }
        unset($route);
        $trunks = $db->all('SELECT * FROM trunks WHERE tenant_id = ? ORDER BY name', [$tid]);
        $times  = $db->all('SELECT * FROM time_conditions WHERE tenant_id = ? ORDER BY name', [$tid]);

        $trunkStatusAvailable = false;
        $trunkRegistrations = [];
        $trunkContacts = [];
        $trunkTimeline = [];
        if ($request->str('tab', 'inbound') === 'trunks') {
            try {
                $mgr = asterisk();
                $sample = (new TrunkStatusTracker())->sample($mgr);
                $trunkStatusAvailable = $sample['available'];
                $trunkRegistrations = $sample['statuses'];
                if ($trunkStatusAvailable) {
                    $trunkContacts = $mgr->contactDetails();
                }
                $windowStart = date('Y-m-d H:i:s', time() - 86400);
                foreach ($db->all(
                    'SELECT h.trunk_id, h.status, h.started_at, h.ended_at
                     FROM trunk_status_history h
                     WHERE h.tenant_id = ? AND (h.ended_at IS NULL OR h.ended_at >= ?)
                     ORDER BY h.started_at, h.id',
                    [$tid, $windowStart]
                ) as $h) {
                    $trunkTimeline[(int) $h['trunk_id']][] = $h;
                }
            } catch (\Throwable) {
                $trunkStatusAvailable = false;
            }
        }
        foreach ($trunks as &$trunk) {
            $trunk['registration_status'] = $trunkRegistrations['trunk-' . $trunk['id']] ?? null;
            $contact = $trunkContacts['trunk-' . $trunk['id'] . '-aor'] ?? null;
            $trunk['latency_ms'] = $contact['rtt'] ?? null;
            $trunk['active_contacts'] = $contact['count'] ?? 0;
            $current = $db->one(
                'SELECT started_at, status FROM trunk_status_history
                 WHERE trunk_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1',
                [(int) $trunk['id']]
            );
            $trunk['status_since'] = $current['started_at'] ?? null;
            $trunk['uptime_seconds'] = $current && strcasecmp((string) $current['status'], 'Registered') === 0
                ? max(0, time() - (strtotime((string) $current['started_at']) ?: time()))
                : null;
            $trunk['timeline_events'] = $trunkTimeline[(int) $trunk['id']] ?? [];
            $trunk['monitor_history'] = $db->all(
                'SELECT status, started_at, ended_at
                 FROM trunk_status_history
                 WHERE tenant_id = ? AND trunk_id = ?
                 ORDER BY started_at DESC, id DESC LIMIT 50',
                [$tid, (int) $trunk['id']]
            );
        }
        unset($trunk);

        // Resolve destination labels for display.
        $resolve = function (string $type, ?int $id) use ($db): string {
            if ($id === null || $id === 0) {
                return $type === 'hangup' ? 'Hang up' : ($type === 'external' ? 'External' : '—');
            }
            $table = self::destTable($type);
            if (!$table) {
                return '—';
            }
            if ($type === 'announcement') {
                $r = $db->one('SELECT name FROM announcements WHERE id = ?', [$id]);
                return $r ? (string) $r['name'] : '—';
            }
            $r = $db->one("SELECT extension, name FROM {$table} WHERE id = ?", [$id]);
            return $r ? trim((string) ($r['extension'] ?? '') . ' — ' . $r['name'], ' —') : '—';
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
            'announcements'=> $db->all('SELECT id, name FROM announcements WHERE tenant_id = ? AND enabled = 1 ORDER BY name', [$tid]),
            'destinations'=> $this->destinationOptions(),
            'destTypes'   => self::DEST_TYPES,
            'trunkStatusAvailable' => $trunkStatusAvailable,
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
            'record'      => $request->int('record', 0),
            'announcement_id' => $this->validAnnouncementId($request->int('announcement_id'), $tid),
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
        if (!$this->validDestination($data['dest_type'], $data['dest_id'], $tid)) {
            Flash::error('Choose a valid tenant destination.');
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
            'record'      => $request->int('record', 0),
            'announcement_id' => $this->validAnnouncementId($request->int('announcement_id'), (int) $row['tenant_id']),
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
        if (!$this->validDestination($data['dest_type'], $data['dest_id'], (int) $row['tenant_id'])) {
            Flash::error('Choose a valid tenant destination.');
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
        $schedules = $this->outboundSchedules($request);
        if ($schedules === null) {
            Flash::error('Check the outbound calling schedule names, days and times.');
            return Response::redirect('/routes?tab=outbound');
        }

        $data = [
            'tenant_id'     => $tid,
            'name'          => $request->str('name'),
            'prefix'        => $request->str('prefix'),
            'match_pattern' => $request->str('match_pattern'),
            'trunk_id'      => $request->int('trunk_id') ?: null,
            'time_condition_id' => null,
            'cid_override'  => $request->str('cid_override'),
            'priority'      => $request->int('priority', 0),
            'enabled'       => $request->bool('enabled', true) ? 1 : 0,
            'record'        => $request->int('record', 0),
        ];

        $v = Validator::make($data, [
            'name'          => 'required|max:120',
            'match_pattern' => 'required|max:60',
            'trunk_id'      => 'required|integer',
        ]);

        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/routes?tab=outbound');
        }
        if (!$this->validTrunkId((int) $data['trunk_id'], $tid)) {
            Flash::error('Choose a trunk from this tenant.');
            return Response::redirect('/routes?tab=outbound');
        }

        $id = $this->db()->insert('outbound_routes', $data);
        $this->syncOutboundSchedules($id, $tid, $schedules);
        $this->audit('route.outbound.create', 'outbound_route', $id);
        $this->applyToAsterisk('route');

        Flash::success('Outbound route added.');
        return Response::redirect('/routes?tab=outbound');
    }

    public function updateOutbound(Request $request): Response
    {
        $row = $this->findScoped('outbound_routes', (int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/routes?tab=outbound');
        }
        $schedules = $this->outboundSchedules($request);
        if ($schedules === null) {
            Flash::error('Check the outbound calling schedule names, days and times.');
            return Response::redirect('/routes?tab=outbound');
        }

        $data = [
            'name'          => $request->str('name'),
            'prefix'        => $request->str('prefix'),
            'match_pattern' => $request->str('match_pattern'),
            'trunk_id'      => $request->int('trunk_id') ?: null,
            'time_condition_id' => null,
            'cid_override'  => $request->str('cid_override'),
            'priority'      => $request->int('priority', 0),
            'enabled'       => $request->bool('enabled', true) ? 1 : 0,
            'record'        => $request->int('record', 0),
        ];

        $v = Validator::make($data, [
            'name'          => 'required|max:120',
            'match_pattern' => 'required|max:60',
            'trunk_id'      => 'required|integer',
        ]);
        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/routes?tab=outbound');
        }
        if (!$this->validTrunkId((int) $data['trunk_id'], (int) $row['tenant_id'])) {
            Flash::error('Choose a trunk from this tenant.');
            return Response::redirect('/routes?tab=outbound');
        }

        $this->db()->update('outbound_routes', (int) $row['id'], $data);
        $this->syncOutboundSchedules((int) $row['id'], (int) $row['tenant_id'], $schedules);
        if (!$data['enabled']) {
            $this->db()->run(
                'UPDATE extensions SET outbound_route_id = NULL WHERE tenant_id = ? AND outbound_route_id = ?',
                [(int) $row['tenant_id'], (int) $row['id']]
            );
        }
        $this->audit('route.outbound.update', 'outbound_route', (int) $row['id']);
        $this->applyToAsterisk('route');

        Flash::success('Outbound route updated.');
        return Response::redirect('/routes?tab=outbound');
    }

    public function destroyOutbound(Request $request): Response
    {
        $row = $this->findScoped('outbound_routes', (int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/routes?tab=outbound');
        }

        $this->db()->run(
            'UPDATE extensions SET outbound_route_id = NULL WHERE tenant_id = ? AND outbound_route_id = ?',
            [(int) $row['tenant_id'], (int) $row['id']]
        );
        $this->db()->delete('outbound_routes', (int) $row['id']);
        $this->audit('route.outbound.delete', 'outbound_route', (int) $row['id']);
        $this->applyToAsterisk('route');

        Flash::success('Outbound route deleted.');
        return Response::redirect('/routes?tab=outbound');
    }

    // ----- trunks --------------------------------------------------------

    public function storeTrunk(Request $request): Response
    {
        $tid = $this->requireTenant();

        $data = [
            'tenant_id'   => $tid,
            'name'        => $request->str('name'),
            'host'        => $request->str('host'),
            'username'    => $request->str('sip_trunk_auth_id', $request->str('username')),
            'secret'      => $request->str('sip_trunk_auth_secret', $request->str('secret')),
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
            return Response::redirect('/routes?tab=trunks');
        }

        $id = $this->db()->insert('trunks', $data);
        $this->audit('route.trunk.create', 'trunk', $id);
        $this->applyToAsterisk('trunk');

        Flash::success('Trunk added.');
        return Response::redirect('/routes?tab=trunks');
    }

    public function updateTrunk(Request $request): Response
    {
        $row = $this->findScoped('trunks', (int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/routes?tab=trunks');
        }

        $data = [
            'name'        => $request->str('name'),
            'host'        => $request->str('host'),
            'username'    => $request->str('sip_trunk_auth_id', $request->str('username')),
            'secret'      => $request->str('sip_trunk_auth_secret', $request->str('secret')),
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
        return Response::redirect('/routes?tab=trunks');
    }

    public function destroyTrunk(Request $request): Response
    {
        $row = $this->findScoped('trunks', (int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/routes?tab=trunks');
        }

        $this->db()->delete('trunks', (int) $row['id']);
        $this->audit('route.trunk.delete', 'trunk', (int) $row['id']);
        $this->applyToAsterisk('trunk');

        Flash::success('Trunk deleted.');
        return Response::redirect('/routes?tab=trunks');
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
            'start_hour'     => $request->str('start_hour') !== '' ? $request->int('start_hour') : null,
            'start_minute'   => $request->int('start_minute', 0),
            'end_hour'       => $request->str('end_hour') !== '' ? $request->int('end_hour') : null,
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
        if ($data['extension'] !== '' && !$this->dialplanNumberAvailable($tid, $data['extension'], 'time_conditions')) {
            Flash::error('That dialplan number is already used by another tenant feature.');
            return Response::redirect('/routes?tab=time');
        }
        if (!$this->validDestination($data['open_type'], $data['open_dest_id'], $tid)
            || !$this->validDestination($data['closed_type'], $data['closed_dest_id'], $tid)) {
            Flash::error('Choose valid tenant destinations for both time-condition branches.');
            return Response::redirect('/routes?tab=time');
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
            'start_hour'     => $request->str('start_hour') !== '' ? $request->int('start_hour') : null,
            'start_minute'   => $request->int('start_minute', 0),
            'end_hour'       => $request->str('end_hour') !== '' ? $request->int('end_hour') : null,
            'end_minute'     => $request->int('end_minute', 0),
            'time_match'     => $request->str('time_match'),
            'open_type'      => $request->str('open_type'),
            'open_dest_id'   => $request->int('open_dest_id') ?: null,
            'closed_type'    => $request->str('closed_type'),
            'closed_dest_id' => $request->int('closed_dest_id') ?: null,
            'priority'       => $request->int('priority', 0),
            'enabled'        => $request->bool('enabled', true) ? 1 : 0,
        ];

        if ($data['extension'] !== '' && !$this->dialplanNumberAvailable(
            (int) $row['tenant_id'], $data['extension'], 'time_conditions', (int) $row['id']
        )) {
            Flash::error('That dialplan number is already used by another tenant feature.');
            return Response::redirect('/routes?tab=time');
        }

        if (!$this->validDestination($data['open_type'], $data['open_dest_id'], (int) $row['tenant_id'])
            || !$this->validDestination($data['closed_type'], $data['closed_dest_id'], (int) $row['tenant_id'])) {
            Flash::error('Choose valid tenant destinations for both time-condition branches.');
            return Response::redirect('/routes?tab=time');
        }
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

        $this->db()->run(
            'UPDATE outbound_routes SET time_condition_id = NULL WHERE tenant_id = ? AND time_condition_id = ?',
            [(int) $row['tenant_id'], (int) $row['id']]
        );
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
            'announcement' => 'announcements',
            default      => null,
        };
    }

    private function validTimeConditionId(int $conditionId, int $tid): ?int
    {
        if ($conditionId <= 0) {
            return null;
        }

        return $this->db()->scalar(
            'SELECT id FROM time_conditions WHERE id = ? AND tenant_id = ? AND enabled = 1',
            [$conditionId, $tid]
        ) ? $conditionId : null;
    }

    /** @return list<array{name:string,days_of_week:string,start_time:string,end_time:string}>|null */
    private function outboundSchedules(Request $request): ?array
    {
        $raw = $request->str('schedules', '[]');
        $rows = json_decode($raw, true);
        if (!is_array($rows) || count($rows) > 20) { return null; }
        $dayNames = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) { return null; }
            $name = trim((string) ($row['name'] ?? ''));
            $days = strtolower(trim((string) ($row['days_of_week'] ?? '*')));
            $start = trim((string) ($row['start_time'] ?? ''));
            $end = trim((string) ($row['end_time'] ?? ''));
            if ($days === 'mon-fri') {
                $days = 'mon&tue&wed&thu&fri';
            } elseif ($days === 'sat-sun') {
                $days = 'sat&sun';
            }
            $selectedDays = $days === '*' ? $dayNames : explode('&', $days);
            if ($name === '' || mb_strlen($name) > 120 || !$selectedDays
                || count($selectedDays) !== count(array_unique($selectedDays))
                || array_diff($selectedDays, $dayNames)
                || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)
                || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end)) {
                return null;
            }
            $result[] = ['name' => $name, 'days_of_week' => $days, 'start_time' => $start, 'end_time' => $end];
        }
        return $result;
    }

    /** @param list<array{name:string,days_of_week:string,start_time:string,end_time:string}> $schedules */
    private function syncOutboundSchedules(int $routeId, int $tenantId, array $schedules): void
    {
        $this->db()->run('DELETE FROM outbound_route_schedules WHERE outbound_route_id = ? AND tenant_id = ?', [$routeId, $tenantId]);
        foreach ($schedules as $ordering => $schedule) {
            $this->db()->insert('outbound_route_schedules', $schedule + [
                'tenant_id' => $tenantId, 'outbound_route_id' => $routeId, 'ordering' => $ordering,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function validAnnouncementId(int $announcementId, int $tid): ?int
    {
        if ($announcementId <= 0) { return null; }
        return $this->db()->scalar(
            'SELECT id FROM announcements WHERE id = ? AND tenant_id = ? AND enabled = 1',
            [$announcementId, $tid]
        ) ? $announcementId : null;
    }

    private function validTrunkId(int $trunkId, int $tenantId): bool
    {
        return $trunkId > 0 && (bool) $this->db()->scalar(
            'SELECT COUNT(*) FROM trunks WHERE id = ? AND tenant_id = ? AND enabled = 1',
            [$trunkId, $tenantId]
        );
    }

    private function validDestination(string $type, mixed $id, int $tenantId): bool
    {
        if (in_array($type, ['hangup', 'external'], true)) { return true; }
        $table = self::destTable($type);
        $id = (int) $id;
        if (!$table || $id <= 0) { return false; }
        return (bool) $this->db()->scalar(
            "SELECT COUNT(*) FROM {$table} WHERE id = ? AND tenant_id = ? AND enabled = 1",
            [$id, $tenantId]
        );
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
            $columns = $type === 'announcement' ? 'id, name' : 'id, extension, name';
            $order = $type === 'announcement' ? 'name' : 'extension';
            foreach ($db->all("SELECT {$columns} FROM {$table} WHERE tenant_id = ? AND enabled = 1 ORDER BY {$order}", [$tid]) as $r) {
                $extension = trim((string) ($r['extension'] ?? ''));
                $opts[$type][] = ['id' => $r['id'], 'label' => ($extension !== '' ? $extension . ' — ' : '') . $r['name']];
            }
        }

        return $opts;
    }
}
