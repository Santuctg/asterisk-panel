<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Support\Str;

/**
 * Devices + auto-provisioning.
 *
 * A device is identified by MAC address. When a phone polls
 * /prov/<mac>.cfg the ProvisioningService renders a vendor-specific file
 * from the linked extension's credentials.
 */
final class DeviceController extends BaseController
{
    /** GET /devices */
    public function index(Request $request): Response
    {
        $tid = $this->requireTenant();

        $rows = $this->db()->all(
            'SELECT d.*, e.extension AS ext, e.name AS ext_name, t.provision_url AS tenant_provision
             FROM devices d
             LEFT JOIN extensions e ON e.id = d.extension_id
             JOIN tenants t ON t.id = d.tenant_id
             WHERE d.tenant_id = ?
             ORDER BY d.mac',
            [$tid]
        );

        $prov = new \App\Services\ProvisioningService();

        // Annotate with the URL each phone should be pointed at.
        foreach ($rows as &$r) {
            $r['provision_url'] = $prov->provisionUrl($r);
        }
        unset($r);

        return $this->view('devices/index', ['rows' => $rows]);
    }

    /** GET /devices/create */
    public function create(Request $request): Response
    {
        return $this->view('devices/form', [
            'row'        => null,
            'extensions' => $this->extensionOptions(),
            'vendors'    => $this->vendors(),
        ]);
    }

    /** POST /devices */
    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();

        $data = $this->collect($request, $tid);

        if ($request->int('extension_id') > 0 && $data['extension_id'] === null) {
            $this->stashOld($request);
            Flash::error('Choose an extension from this tenant.');
            return Response::redirect('/devices/create');
        }

        $v = Validator::make($data, [
            'mac'           => 'required|mac',
            'vendor'        => 'required',
            'extension_id'  => 'nullable|integer|exists:extensions,id',
            'description'   => 'nullable|max:160',
        ]);
        $data['tenant_id'] = $tid;
        $data['mac']       = Str::macCompact($data['mac']);
        $data['provision_token'] = bin2hex(random_bytes(32));

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect('/devices/create');
        }

        $dup = $this->db()->scalar('SELECT COUNT(*) FROM devices WHERE mac = ?', [$data['mac']]);
        if ($dup) {
            $this->stashOld($request);
            Flash::error('A device with that MAC address already exists.');
            return Response::redirect('/devices/create');
        }

        $id = $this->db()->insert('devices', $data);

        $this->clearOld();
        $this->audit('device.create', 'device', $id, ['mac' => $data['mac'], 'vendor' => $data['vendor']]);
        $this->applyToAsterisk('device');

        Flash::success('Device added. Point the phone at its provisioning URL to finish setup.');
        return Response::redirect('/devices');
    }

    /** GET /devices/{id}/edit */
    public function edit(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            Flash::error('Device not found.');
            return Response::redirect('/devices');
        }

        $prov = new \App\Services\ProvisioningService();

        return $this->view('devices/form', [
            'row'        => $row,
            'extensions' => $this->extensionOptions(),
            'vendors'    => $this->vendors(),
            'provision_url' => $prov->provisionUrl($row),
        ]);
    }

    /** POST /devices/{id} */
    public function update(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/devices');
        }

        $data = $this->collect($request, (int) $row['tenant_id']);
        $data['mac'] = Str::macCompact($data['mac']);

        if ($request->int('extension_id') > 0 && $data['extension_id'] === null) {
            $this->stashOld($request);
            Flash::error('Choose an extension from this tenant.');
            return Response::redirect("/devices/{$row['id']}/edit");
        }

        $v = Validator::make($data, [
            'mac'          => 'required|mac',
            'vendor'       => 'required',
            'extension_id' => 'nullable|integer',
        ]);

        if ($v->fails()) {
            $this->stashOld($request);
            Flash::error($v->firstError());
            return Response::redirect("/devices/{$row['id']}/edit");
        }

        $dup = $this->db()->scalar(
            'SELECT COUNT(*) FROM devices WHERE mac = ? AND id != ?',
            [$data['mac'], (int) $row['id']]
        );
        if ($dup) {
            $this->stashOld($request);
            Flash::error('Another device already uses that MAC address.');
            return Response::redirect("/devices/{$row['id']}/edit");
        }

        $this->db()->update('devices', (int) $row['id'], $data);
        $this->invalidateCache($data['mac']);

        $this->clearOld();
        $this->audit('device.update', 'device', (int) $row['id']);
        $this->applyToAsterisk('device');

        Flash::success('Device updated.');
        return Response::redirect('/devices');
    }

    /** DELETE /devices/{id} */
    public function destroy(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/devices');
        }

        $this->db()->delete('devices', (int) $row['id']);
        $this->invalidateCache((string) $row['mac']);

        $this->audit('device.delete', 'device', (int) $row['id'], ['mac' => $row['mac']]);
        Flash::success('Device removed.');
        return Response::redirect('/devices');
    }

    /** POST /devices/{id}/resync — drop the cache so the phone re-fetches. */
    public function resync(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) {
            return Response::redirect('/devices');
        }

        $this->invalidateCache((string) $row['mac']);
        $this->audit('device.resync', 'device', (int) $row['id']);

        Flash::success('Provisioning cache cleared. Power-cycle or resync the phone to pull the new config.');
        return Response::redirect('/devices');
    }

    // ----- helpers -------------------------------------------------------

    private function collect(Request $request, int $tid): array
    {
        $extId = $request->int('extension_id');
        $extId = $extId > 0 && $this->db()->scalar(
            'SELECT COUNT(*) FROM extensions WHERE id = ? AND tenant_id = ? AND enabled = 1',
            [$extId, $tid]
        ) ? $extId : null;

        return [
            'extension_id'  => $extId,
            'mac'           => $request->str('mac'),
            'vendor'        => $request->str('vendor', 'generic'),
            'model'         => $request->str('model'),
            'description'   => $request->str('description'),
            'firmware'      => $request->str('firmware'),
            'provision_path'=> $request->str('provision_path'),
            'enabled'       => $request->bool('enabled', true) ? 1 : 0,
        ];
    }

    private function findScoped(int $id): ?array
    {
        $tid = $this->tenantId();
        if (!$tid) {
            return null;
        }
        if (auth()->isSuperAdmin()) {
            return $this->db()->one('SELECT * FROM devices WHERE id = ?', [$id]);
        }
        return $this->db()->one('SELECT * FROM devices WHERE id = ? AND tenant_id = ?', [$id, $tid]);
    }

    private function extensionOptions(): array
    {
        $tid = $this->tenantId();
        return $this->db()->all(
            'SELECT id, extension, name FROM extensions WHERE tenant_id = ? ORDER BY extension',
            [$tid]
        );
    }

    private function vendors(): array
    {
        return ['yealink', 'polycom', 'grandstream', 'fanvil', 'cisco', 'generic'];
    }

    private function invalidateCache(string $mac): void
    {
        $file = BASE_PATH . '/storage/provision/' . Str::macCompact($mac) . '.cfg';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
