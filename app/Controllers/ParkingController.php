<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Call parking lots (res_parking). Each lot gets its own extension to park
 * on and a block of orbit slots callers wait in until picked up.
 */
final class ParkingController extends BaseController
{
    /** GET /parking */
    public function index(Request $request): Response
    {
        $tid  = $this->requireTenant();
        $rows = $this->db()->all(
            'SELECT * FROM parking_lots WHERE tenant_id = ? ORDER BY extension',
            [$tid]
        );

        // Show any currently parked calls (live, best-effort).
        $parked = [];
        try {
            $parked = asterisk()->parkedCalls();
        } catch (\Throwable) {
            $parked = [];
        }

        return $this->view('parking/index', ['rows' => $rows, 'parked' => $parked]);
    }

    /** POST /parking */
    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();

        $data = $this->collect($request);
        $data['tenant_id'] = $tid;

        $v = Validator::make($data, [
            'name'           => 'required|max:120',
            'extension'      => 'required|digits_between:2,6',
            'parked_range'   => 'required',
            'timeout'        => 'required|integer',
            'return_extension' => 'nullable|digits_between:2,6',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/parking');
        }

        $dup = $this->db()->scalar(
            'SELECT COUNT(*) FROM parking_lots WHERE tenant_id = ? AND extension = ?',
            [$tid, $data['extension']]
        );
        if ($dup) {
            $this->stashOld($request);
            Flash::error('That park extension is already in use.');
            return Response::redirect('/parking');
        }

        $id = $this->db()->insert('parking_lots', $data);

        $this->clearOld();
        $this->audit('parking.create', 'parking_lot', $id);
        $this->applyToAsterisk('parking');

        Flash::success('Parking lot created.');
        return Response::redirect('/parking');
    }

    /** POST /parking/{id} */
    public function update(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/parking');
        }

        $data = $this->collect($request);

        $v = Validator::make($data, [
            'name'         => 'required|max:120',
            'extension'    => 'required|digits_between:2,6',
            'parked_range' => 'required',
            'timeout'      => 'required|integer',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/parking');
        }

        $this->db()->update('parking_lots', (int) $row['id'], $data);

        $this->clearOld();
        $this->audit('parking.update', 'parking_lot', (int) $row['id']);
        $this->applyToAsterisk('parking');

        Flash::success('Parking lot updated.');
        return Response::redirect('/parking');
    }

    /** DELETE /parking/{id} */
    public function destroy(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/parking');
        }

        $this->db()->delete('parking_lots', (int) $row['id']);
        $this->audit('parking.delete', 'parking_lot', (int) $row['id']);
        $this->applyToAsterisk('parking');

        Flash::success('Parking lot deleted.');
        return Response::redirect('/parking');
    }

    private function collect(Request $request): array
    {
        return [
            'name'              => $request->str('name'),
            'extension'         => $request->str('extension'),
            'parked_range'      => $request->str('parked_range', '701-750'),
            'return_extension'  => $request->str('return_extension'),
            'timeout'           => $request->int('timeout', 45),
            'courtesy_tone'     => $request->str('courtesy_tone', 'beep'),
            'allow_transfer'    => $request->bool('allow_transfer', true) ? 1 : 0,
            'allow_repark'      => $request->bool('allow_repark', true) ? 1 : 0,
            'allow_hangup'      => $request->bool('allow_hangup') ? 1 : 0,
            'play_sounds'       => $request->bool('play_sounds', true) ? 1 : 0,
            'record'            => $request->bool('record') ? 1 : 0,
            'enabled'           => $request->bool('enabled', true) ? 1 : 0,
        ];
    }

    private function findScoped(int $id): ?array
    {
        $tid = $this->tenantId();
        if (!$tid) {
            return null;
        }
        if (auth()->isSuperAdmin()) {
            return $this->db()->one('SELECT * FROM parking_lots WHERE id = ?', [$id]);
        }
        return $this->db()->one('SELECT * FROM parking_lots WHERE id = ? AND tenant_id = ?', [$id, $tid]);
    }
}
