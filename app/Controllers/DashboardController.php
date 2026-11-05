<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\TenantHost;

/**
 * Dashboard: system health, quick counts, live channels and per-tenant
 * switcher for multi-tenant installs.
 */
final class DashboardController extends BaseController
{
    /** GET / or /dashboard */
    public function index(Request $request): Response
    {
        $tid = $this->tenantId();
        $db  = $this->db();

        $counts = [
            'extensions'  => $tid ? $db->count('extensions', 'tenant_id = ?', [$tid]) : 0,
            'devices'     => $tid ? $db->count('devices', 'tenant_id = ? AND enabled = 1', [$tid]) : 0,
            'ring_groups' => $tid ? $db->count('ring_groups', 'tenant_id = ? AND enabled = 1', [$tid]) : 0,
            'ivrs'        => $tid ? $db->count('ivr_menus', 'tenant_id = ? AND enabled = 1', [$tid]) : 0,
            'queues'      => $tid ? $db->count('queues', 'tenant_id = ? AND enabled = 1', [$tid]) : 0,
            'conferences' => $tid ? $db->count('conferences', 'tenant_id = ? AND enabled = 1', [$tid]) : 0,
            'trunks'      => $tid ? $db->count('trunks', 'tenant_id = ? AND enabled = 1', [$tid]) : 0,
            'voicemail'   => $tid ? $db->count('voicemail', 'tenant_id = ? AND enabled = 1', [$tid]) : 0,
        ];

        // Live Asterisk data — degrade gracefully when AMI is unreachable.
        $online = false;
        $channels = [];
        $astVersion = 'unavailable';
        $activeChannels = 0;
        $registered = 0;

        try {
            $mgr  = asterisk();
            $online = $mgr->isOnline();
            if ($online) {
                $astVersion    = $mgr->version()['version'] ?? 'unknown';
                $channels      = $tid ? $mgr->tenantChannels($tid, 12) : [];
                $activeChannels = count($channels);
                if ($tid) {
                    $tenant = $db->one('SELECT context, domain FROM tenants WHERE id = ?', [$tid]) ?? [];
                    $extensions = $db->all(
                        'SELECT extension FROM extensions WHERE tenant_id = ? AND enabled = 1',
                        [$tid]
                    );
                    $realm = strtolower(rtrim(trim((string) ($tenant['domain'] ?? '')), '.'))
                        ?: (string) ($tenant['context'] ?? '');
                    $registered = self::registeredPhoneCount(
                        $extensions,
                        $mgr->contactStatuses(),
                        $realm,
                        (string) ($tenant['context'] ?? '')
                    );
                }
            }
        } catch (\Throwable $e) {
            \App\Core\Logger::info('Dashboard: Asterisk unavailable', ['error' => $e->getMessage()]);
        }

        // Recent calls for the active tenant (if CDR is being collected).
        $recentCalls = $db->all(
            'SELECT * FROM cdr WHERE tenant_id = ? ORDER BY calldate DESC LIMIT 10',
            [$tid]
        );

        return $this->view('dashboard/index', [
            'tid'             => $tid,
            'counts'          => $counts,
            'online'          => $online,
            'channels'        => $channels,
            'astVersion'      => $astVersion,
            'activeChannels'  => $activeChannels,
            'registered'      => $registered,
            'recentCalls'     => $recentCalls,
            'tenants'         => $this->tenantOptions(),
        ]);
    }

    /** Count each enabled extension once, using only the active tenant's AORs. */
    public static function registeredPhoneCount(array $extensions, array $contacts, string $realm, string $context): int
    {
        $context = preg_replace('/[^a-zA-Z0-9_]/', '_', $context) ?: '';
        $count = 0;

        foreach ($extensions as $extension) {
            $number = (string) ($extension['extension'] ?? '');
            if ($number === '') {
                continue;
            }
            $keys = [
                $number . '@' . $realm,
                $context . '_' . $number . '-phone-web',
            ];
            foreach ($keys as $key) {
                if (isset($contacts[$key])) {
                    $count++;
                    break;
                }
            }
        }

        return $count;
    }

    /** GET /dashboard/live-calls — tenant-scoped polling feed. */
    public function liveCalls(Request $request): Response
    {
        $tid = $this->tenantId();
        $online = false;
        $channels = [];

        try {
            $mgr = asterisk();
            $online = $mgr->isOnline();
            if ($online && $tid) {
                $channels = $mgr->tenantChannels($tid, 30);
            }
        } catch (\Throwable $e) {
            \App\Core\Logger::info('Live calls feed: Asterisk unavailable', ['error' => $e->getMessage()]);
        }

        return $this->json([
            'online' => $online,
            'count' => count($channels),
            'channels' => array_values($channels),
        ]);
    }

    /** POST /dashboard/switch-tenant */
    public function switchTenant(Request $request): Response
    {
        $tid = $request->int('tenant_id');

        // Non-superadmins may only switch to their own tenant.
        if (!auth()->isSuperAdmin()) {
            $user = $this->user();
            $tid  = (int) ($user['tenant_id'] ?? 0);
        } elseif ($hostTenant = TenantHost::tenantIdForRequest()) {
            $tid = $hostTenant;
        }

        if ($tid) {
            $exists = $this->db()->scalar('SELECT id FROM tenants WHERE id = ? AND enabled = 1', [$tid]);
            if ($exists) {
                Session::put('active_tenant', $tid);
            }
        }

        return Response::redirect('/dashboard');
    }

    /** Tenants the current user is allowed to see. */
    private function tenantOptions(): array
    {
        $user = $this->user();

        if (auth()->isSuperAdmin()) {
            if ($hostTenant = TenantHost::tenantIdForRequest()) {
                return $this->db()->all('SELECT id, name, context FROM tenants WHERE id = ? AND enabled = 1', [$hostTenant]);
            }
            return $this->db()->all('SELECT id, name, context FROM tenants WHERE enabled = 1 ORDER BY name');
        }

        if (!empty($user['tenant_id'])) {
            return $this->db()->all(
                'SELECT id, name, context FROM tenants WHERE id = ? AND enabled = 1',
                [$user['tenant_id']]
            );
        }

        return [];
    }
}
