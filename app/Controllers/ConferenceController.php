<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Conference rooms (ConfBridge). Each room has a participant PIN and an
 * optional admin PIN for moderation.
 */
final class ConferenceController extends BaseController
{
    /** GET /conferences */
    public function index(Request $request): Response
    {
        $tid  = $this->requireTenant();
        $rows = $this->db()->all(
            'SELECT * FROM conferences WHERE tenant_id = ? ORDER BY extension',
            [$tid]
        );

        $live = [];
        try {
            $live = asterisk()->conferences();
        } catch (\Throwable) {
            $live = [];
        }

        return $this->view('conferences/index', [
            'rows'       => $rows,
            'live'       => $live,
            'mohClasses' => $this->db()->all('SELECT name FROM moh_classes ORDER BY name'),
        ]);
    }

    /** POST /conferences */
    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();

        $data = $this->collect($request);
        $data['tenant_id'] = $tid;

        $v = Validator::make($data, [
            'extension' => 'required|digits_between:2,6',
            'name'      => 'required|max:120',
            'pin'       => 'required|digits_between:4,10',
            'admin_pin' => 'nullable|digits_between:4,10',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/conferences');
        }

        $dup = $this->db()->scalar(
            'SELECT COUNT(*) FROM conferences WHERE tenant_id = ? AND extension = ?',
            [$tid, $data['extension']]
        );
        if ($dup) {
            $this->stashOld($request);
            Flash::error('That extension number is already used by a conference.');
            return Response::redirect('/conferences');
        }

        $id = $this->db()->insert('conferences', $data);

        $this->clearOld();
        $this->audit('conference.create', 'conference', $id);
        $this->applyToAsterisk('conference');

        Flash::success('Conference room created.');
        return Response::redirect('/conferences');
    }

    /** POST /conferences/{id} */
    public function update(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/conferences');
        }

        $data = $this->collect($request);

        $v = Validator::make($data, [
            'extension' => 'required|digits_between:2,6',
            'name'      => 'required|max:120',
            'pin'       => 'required|digits_between:4,10',
            'admin_pin' => 'nullable|digits_between:4,10',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/conferences');
        }

        $this->db()->update('conferences', (int) $row['id'], $data);

        $this->clearOld();
        $this->audit('conference.update', 'conference', (int) $row['id']);
        $this->applyToAsterisk('conference');

        Flash::success('Conference room updated.');
        return Response::redirect('/conferences');
    }

    /** DELETE /conferences/{id} */
    public function destroy(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/conferences');
        }

        $this->db()->delete('conferences', (int) $row['id']);
        $this->audit('conference.delete', 'conference', (int) $row['id']);
        $this->applyToAsterisk('conference');

        Flash::success('Conference room deleted.');
        return Response::redirect('/conferences');
    }

    private function collect(Request $request): array
    {
        return [
            'extension'  => $request->str('extension'),
            'name'       => $request->str('name'),
            'pin'        => $request->str('pin'),
            'admin_pin'  => $request->str('admin_pin'),
            'profile'    => $request->str('profile', 'default'),
            'max_members'=> $request->int('max_members', 0),
            'moh_class'  => $request->str('moh_class', 'default'),
            'record'     => $request->bool('record') ? 1 : 0,
            'enabled'    => $request->bool('enabled', true) ? 1 : 0,
        ];
    }

    private function findScoped(int $id): ?array
    {
        $tid = $this->tenantId();
        if (!$tid) {
            return null;
        }
        if (auth()->isSuperAdmin()) {
            return $this->db()->one('SELECT * FROM conferences WHERE id = ?', [$id]);
        }
        return $this->db()->one('SELECT * FROM conferences WHERE id = ? AND tenant_id = ?', [$id, $tid]);
    }
}
