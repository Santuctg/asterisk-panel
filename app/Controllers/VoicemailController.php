<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Voicemail boxes + voicemail-to-email settings.
 *
 * Each mailbox maps 1:1 to an extension. Email delivery is performed by
 * Asterisk itself (app_voicemail) via the `mailcmd` hook — see
 * scripts/asterisk-mail.php and docs/asterisk-setup.md.
 */
final class VoicemailController extends BaseController
{
    /** GET /voicemail */
    public function index(Request $request): Response
    {
        $tid = $this->requireTenant();

        $rows = $this->db()->all(
            'SELECT v.*, e.extension AS ext, e.name AS ext_name
             FROM voicemail v
             JOIN extensions e ON e.id = v.extension_id
             WHERE v.tenant_id = ?
             ORDER BY e.extension',
            [$tid]
        );

        return $this->view('voicemail/index', ['rows' => $rows]);
    }

    /** GET /voicemail/create */
    public function create(Request $request): Response
    {
        $tid = $this->tenantId();

        // Only extensions that don't already have a mailbox.
        $available = $this->db()->all(
            'SELECT e.id, e.extension, e.name FROM extensions e
             LEFT JOIN voicemail v ON v.extension_id = e.id
             WHERE e.tenant_id = ? AND v.id IS NULL
             ORDER BY e.extension',
            [$tid]
        );

        return $this->view('voicemail/form', [
            'row'       => null,
            'available'=> $available,
        ]);
    }

    /** POST /voicemail */
    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();

        $data = $this->collect($request, $tid);

        $v = Validator::make($data, [
            'extension_id' => 'required|integer|exists:extensions,id',
            'pin'          => 'required|digits_between:4,10',
            'name'         => 'required|max:120',
            'email'        => 'nullable|email',
            'timezone'     => 'required',
        ]);
        $data['tenant_id'] = $tid;

        if ($v->fails() || !is_valid_timezone($data['timezone'])) {
            $this->stashOld($request);
            Flash::error($v->firstError() ?? 'The selected timezone is invalid.');
            return Response::redirect('/voicemail/create');
        }

        $dup = $this->db()->scalar(
            'SELECT COUNT(*) FROM voicemail WHERE extension_id = ?',
            [$data['extension_id']]
        );
        if ($dup) {
            $this->stashOld($request);
            Flash::error('That extension already has a voicemail box.');
            return Response::redirect('/voicemail/create');
        }

        $id = $this->db()->insert('voicemail', $data);

        // Ensure the extension itself is flagged as voicemail-enabled.
        $this->db()->update('extensions', (int) $data['extension_id'], ['vm_enabled' => 1]);

        $this->clearOld();
        $this->audit('voicemail.create', 'voicemail', $id);
        $this->applyToAsterisk('voicemail');

        Flash::success('Voicemail box created.');
        return Response::redirect('/voicemail');
    }

    /** GET /voicemail/{id}/edit */
    public function edit(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            Flash::error('Voicemail box not found.');
            return Response::redirect('/voicemail');
        }

        return $this->view('voicemail/form', [
            'row'       => $row,
            'available'=> [],
        ]);
    }

    /** POST /voicemail/{id} */
    public function update(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            Flash::error('Voicemail box not found.');
            return Response::redirect('/voicemail');
        }

        $data = $this->collect($request, (int) $row['tenant_id']);

        $v = Validator::make($data, [
            'pin'   => 'required|digits_between:4,10',
            'name'  => 'required|max:120',
            'email' => 'nullable|email',
            'timezone' => 'required',
        ]);

        if ($v->fails() || !is_valid_timezone($data['timezone'])) {
            $this->stashOld($request);
            Flash::error($v->firstError() ?? 'The selected timezone is invalid.');
            return Response::redirect("/voicemail/{$row['id']}/edit");
        }

        unset($data['extension_id']); // immutable once created
        $this->db()->update('voicemail', (int) $row['id'], $data);

        $this->clearOld();
        $this->audit('voicemail.update', 'voicemail', (int) $row['id']);
        $this->applyToAsterisk('voicemail');

        Flash::success('Voicemail box updated.');
        return Response::redirect('/voicemail');
    }

    /** DELETE /voicemail/{id} */
    public function destroy(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/voicemail');
        }

        $this->db()->delete('voicemail', (int) $row['id']);
        $this->db()->update('extensions', (int) $row['extension_id'], ['vm_enabled' => 0]);

        $this->audit('voicemail.delete', 'voicemail', (int) $row['id']);
        $this->applyToAsterisk('voicemail');

        Flash::success('Voicemail box deleted.');
        return Response::redirect('/voicemail');
    }

    private function collect(Request $request, int $tid): array
    {
        return [
            'extension_id'      => $request->int('extension_id'),
            'pin'               => $request->str('pin'),
            'name'              => $request->str('name'),
            'email'             => $request->str('email'),
            'pager_email'       => $request->str('pager_email'),
            'vm_password'       => $request->str('vm_password'),
            'attach'            => $request->bool('attach', true) ? 1 : 0,
            'say_cid'           => $request->bool('say_cid', true) ? 1 : 0,
            'envelope'          => $request->bool('envelope', true) ? 1 : 0,
            'skip_instructions' => $request->bool('skip_instructions') ? 1 : 0,
            'timezone'          => $request->str('timezone', 'America/New_York'),
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
            return $this->db()->one('SELECT * FROM voicemail WHERE id = ?', [$id]);
        }
        return $this->db()->one('SELECT * FROM voicemail WHERE id = ? AND tenant_id = ?', [$id, $tid]);
    }
}
