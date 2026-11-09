<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/** Fast tenant-scoped search for the authenticated panel header. */
final class SearchController extends BaseController
{
    public function index(Request $request): Response
    {
        $query = trim($request->str('q'));
        $tenantId = $this->tenantId();
        if ($query === '' || !$tenantId) {
            return $this->json(['results' => []])->withHeader('Cache-Control', 'no-store, private');
        }
        $like = $this->likePattern(mb_substr($query, 0, 80));
        $results = [];
        $auth = $this->app->auth();

        if ($auth->can('extension.view')) {
            foreach ($this->db()->all(
                "SELECT id, extension, name FROM extensions WHERE tenant_id = ? AND (extension LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!') ORDER BY name, extension LIMIT 6",
                [$tenantId, $like, $like]
            ) as $row) {
                $results[] = [
                    'kind' => 'Extension', 'title' => (string) $row['name'], 'meta' => (string) $row['extension'],
                    'url' => $auth->can('extension.manage') ? '/extensions/' . (int) $row['id'] . '/edit' : '/extensions?q=' . rawurlencode($query),
                ];
            }
        }
        if ($auth->can('contact.view')) {
            foreach ($this->db()->all(
                "SELECT id, name, phone, company FROM contacts WHERE tenant_id = ? AND (name LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR company LIKE ? ESCAPE '!') ORDER BY name, phone LIMIT 6",
                [$tenantId, $like, $like, $like, $like]
            ) as $row) {
                $meta = (string) $row['phone'] . ((string) ($row['company'] ?? '') !== '' ? ' · ' . $row['company'] : '');
                $results[] = [
                    'kind' => 'Contact', 'title' => (string) $row['name'], 'meta' => $meta,
                    'url' => $auth->can('contact.manage') ? '/contacts/' . (int) $row['id'] . '/edit' : '/contacts?q=' . rawurlencode($query),
                ];
            }
        }
        if ($auth->can('device.view')) {
            foreach ($this->db()->all(
                "SELECT d.id, d.mac, d.vendor, e.extension, e.name FROM devices d LEFT JOIN extensions e ON e.id = d.extension_id AND e.tenant_id = d.tenant_id WHERE d.tenant_id = ? AND (d.mac LIKE ? ESCAPE '!' OR d.vendor LIKE ? ESCAPE '!' OR e.extension LIKE ? ESCAPE '!' OR e.name LIKE ? ESCAPE '!') ORDER BY e.name, d.mac LIMIT 4",
                [$tenantId, $like, $like, $like, $like]
            ) as $row) {
                $results[] = [
                    'kind' => 'Device', 'title' => (string) (($row['name'] ?? '') ?: ($row['vendor'] ?? 'Device')),
                    'meta' => trim((string) ($row['extension'] ?? '') . ' · ' . (string) $row['mac'], ' ·'),
                    'url' => $auth->can('device.manage') ? '/devices/' . (int) $row['id'] . '/edit' : '/devices',
                ];
            }
        }
        if ($auth->can('route.view')) {
            foreach ($this->db()->all(
                "SELECT id, name, host FROM trunks WHERE tenant_id = ? AND (name LIKE ? ESCAPE '!' OR host LIKE ? ESCAPE '!') ORDER BY name LIMIT 4",
                [$tenantId, $like, $like]
            ) as $row) {
                $results[] = ['kind' => 'Trunk', 'title' => (string) $row['name'], 'meta' => (string) $row['host'], 'url' => '/routes?q=' . rawurlencode($query)];
            }
        }

        return $this->json(['results' => array_slice($results, 0, 20)])
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
