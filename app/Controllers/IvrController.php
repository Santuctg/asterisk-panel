<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * IVR menus (dialplan-based). Keys map to destinations (extension, ring
 * group, queue, conference, another IVR, external number, hangup).
 */
final class IvrController extends BaseController
{
    /** GET /ivr */
    public function index(Request $request): Response
    {
        $tid  = $this->requireTenant();
        $rows = $this->db()->all(
            'SELECT * FROM ivr_menus WHERE tenant_id = ? ORDER BY extension',
            [$tid]
        );

        foreach ($rows as &$r) {
            $r['keys'] = json_decode((string) $r['keys'], true) ?: [];
            foreach ($r['keys'] as $k => $d) {
                $r['keys'][$k]['label'] = $this->destinationLabel($d['type'], (int) $d['id']);
            }
        }
        unset($r);

        return $this->view('ivr/index', ['rows' => $rows, 'destinations' => $this->destinationOptions()]);
    }

    /** GET /ivr/create */
    public function create(Request $request): Response
    {
        return $this->view('ivr/form', [
            'row'          => null,
            'destinations' => $this->destinationOptions(),
            'next'         => $this->suggestNext(),
        ]);
    }

    /** POST /ivr */
    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();

        $data = $this->collect($request);
        $data['tenant_id'] = $tid;

        $v = Validator::make($data, [
            'extension' => 'required|digits_between:2,6',
            'name'      => 'required|max:120',
            'greeting'  => 'nullable|max:255',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/ivr/create');
        }

        $dup = $this->db()->scalar(
            'SELECT COUNT(*) FROM ivr_menus WHERE tenant_id = ? AND extension = ?',
            [$tid, $data['extension']]
        );
        if ($dup) {
            $this->stashOld($request);
            Flash::error('That extension number is already used by an IVR.');
            return Response::redirect('/ivr/create');
        }

        $id = $this->db()->insert('ivr_menus', $data);

        $this->clearOld();
        $this->audit('ivr.create', 'ivr_menu', $id);
        $this->applyToAsterisk('ivr');

        Flash::success('IVR menu created.');
        return Response::redirect('/ivr');
    }

    /** GET /ivr/{id}/edit */
    public function edit(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/ivr');
        }

        $row['keys'] = json_decode((string) $row['keys'], true) ?: [];

        return $this->view('ivr/form', [
            'row'          => $row,
            'destinations' => $this->destinationOptions(),
            'next'         => [],
        ]);
    }

    /** POST /ivr/{id} */
    public function update(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/ivr');
        }

        $data = $this->collect($request);

        $v = Validator::make($data, [
            'extension' => 'required|digits_between:2,6',
            'name'      => 'required|max:120',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect("/ivr/{$row['id']}/edit");
        }

        $this->db()->update('ivr_menus', (int) $row['id'], $data);

        $this->clearOld();
        $this->audit('ivr.update', 'ivr_menu', (int) $row['id']);
        $this->applyToAsterisk('ivr');

        Flash::success('IVR menu updated.');
        return Response::redirect('/ivr');
    }

    /** DELETE /ivr/{id} */
    public function destroy(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/ivr');
        }

        $this->db()->delete('ivr_menus', (int) $row['id']);
        $this->audit('ivr.delete', 'ivr_menu', (int) $row['id']);
        $this->applyToAsterisk('ivr');

        Flash::success('IVR menu deleted.');
        return Response::redirect('/ivr');
    }

    /** Gather key => destination mappings from the form. */
    private function collect(Request $request): array
    {
        $keys = [];
        $keyArr = (array) $request->input('key', []);
        $typeArr = (array) $request->input('key_type', []);
        $destArr = (array) $request->input('key_dest', []);

        foreach ($keyArr as $i => $k) {
            $k = trim((string) $k);
            if ($k === '' || !isset($typeArr[$i])) {
                continue;
            }
            $keys[$k] = [
                'type' => (string) $typeArr[$i],
                'id'   => (int) ($destArr[$i] ?? 0),
            ];
        }

        return [
            'extension'       => $request->str('extension'),
            'name'            => $request->str('name'),
            'greeting'        => $request->str('greeting'),
            'timeout'         => $request->int('timeout', 3),
            'response_timeout'=> $request->int('response_timeout', 8),
            'max_retries'     => $request->int('max_retries', 3),
            'keys'            => json_encode($keys, JSON_UNESCAPED_SLASHES),
            'timeout_action'  => $request->str('timeout_action'),
            'timeout_dest_id' => $request->int('timeout_dest_id') ?: null,
            'enabled'         => $request->bool('enabled', true) ? 1 : 0,
        ];
    }

    private function findScoped(int $id): ?array
    {
        $tid = $this->tenantId();
        if (!$tid) {
            return null;
        }
        if (auth()->isSuperAdmin()) {
            return $this->db()->one('SELECT * FROM ivr_menus WHERE id = ?', [$id]);
        }
        return $this->db()->one('SELECT * FROM ivr_menus WHERE id = ? AND tenant_id = ?', [$id, $tid]);
    }

    /**
     * Build the list of routable destinations for the pickers, grouped by
     * type. Shared with RouteController and CallCenterController via a trait
     * would be nicer; kept here as a public helper for now.
     */
    public function destinationOptions(): array
    {
        $tid  = $this->tenantId();
        $db   = $this->db();
        $opts = [];

        foreach ($db->all('SELECT id, extension, name FROM extensions WHERE tenant_id = ? AND enabled = 1 ORDER BY extension', [$tid]) as $r) {
            $opts['extension'][] = ['id' => $r['id'], 'label' => "{$r['extension']} — {$r['name']}"];
        }
        foreach ($db->all('SELECT id, extension, name FROM ring_groups WHERE tenant_id = ? AND enabled = 1 ORDER BY extension', [$tid]) as $r) {
            $opts['ringgroup'][] = ['id' => $r['id'], 'label' => "{$r['extension']} — {$r['name']}"];
        }
        foreach ($db->all('SELECT id, extension, name FROM ivr_menus WHERE tenant_id = ? AND enabled = 1 ORDER BY extension', [$tid]) as $r) {
            $opts['ivr'][] = ['id' => $r['id'], 'label' => "{$r['extension']} — {$r['name']}"];
        }
        foreach ($db->all('SELECT id, extension, name FROM queues WHERE tenant_id = ? AND enabled = 1 ORDER BY extension', [$tid]) as $r) {
            $opts['queue'][] = ['id' => $r['id'], 'label' => "{$r['extension']} — {$r['name']}"];
        }
        foreach ($db->all('SELECT id, extension, name FROM conferences WHERE tenant_id = ? AND enabled = 1 ORDER BY extension', [$tid]) as $r) {
            $opts['conference'][] = ['id' => $r['id'], 'label' => "{$r['extension']} — {$r['name']}"];
        }
        foreach ($db->all('SELECT id, extension, name FROM parking_lots WHERE tenant_id = ? AND enabled = 1 ORDER BY extension', [$tid]) as $r) {
            $opts['parking'][] = ['id' => $r['id'], 'label' => "{$r['extension']} — {$r['name']}"];
        }
        foreach ($db->all('SELECT id, extension, name FROM time_conditions WHERE tenant_id = ? AND enabled = 1 ORDER BY name', [$tid]) as $r) {
            $opts['timecond'][] = ['id' => $r['id'], 'label' => "{$r['name']}"];
        }
        foreach ($db->all('SELECT id, name FROM announcements WHERE tenant_id = ? AND enabled = 1 ORDER BY name', [$tid]) as $r) {
            $opts['announcement'][] = ['id' => $r['id'], 'label' => $r['name']];
        }

        return $opts;
    }

    private function destinationLabel(string $type, int $id): string
    {
        $table = match ($type) {
            'extension'  => 'extensions',
            'ringgroup'  => 'ring_groups',
            'ivr'        => 'ivr_menus',
            'queue'      => 'queues',
            'conference' => 'conferences',
            'parking'    => 'parking_lots',
            'timecond'   => 'time_conditions',
            'announcement' => 'announcements',
            default      => '',
        };

        if ($table === '') {
            return $type === 'hangup' ? 'Hang up' : ($type === 'external' ? 'External number' : '—');
        }

        if ($type === 'announcement') {
            $r = $this->db()->one('SELECT name FROM announcements WHERE id = ?', [$id]);
            return $r ? (string) $r['name'] : '—';
        }
        $r = $this->db()->one("SELECT extension, name FROM {$table} WHERE id = ?", [$id]);
        return $r ? trim((string) ($r['extension'] ?? '') . ' — ' . $r['name'], ' —') : '—';
    }

    private function suggestNext(): array
    {
        $tid = $this->tenantId();
        for ($n = 300; $n <= 399; $n++) {
            $used = $this->db()->scalar(
                'SELECT COUNT(*) FROM ivr_menus WHERE tenant_id = ? AND extension = ?',
                [$tid, (string) $n]
            );
            if (!$used) {
                return ['extension' => (string) $n];
            }
        }
        return ['extension' => ''];
    }
}
