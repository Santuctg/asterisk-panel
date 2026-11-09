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
                $channels      = $tid ? $this->labelTrunkChannels($mgr->tenantChannels($tid, 12), $tid) : [];
                $activeChannels = self::activeCallCount($channels);
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
            $keys = \App\Services\Asterisk\AsteriskManager::extensionContactKeys($number, $realm, $context);
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
                $channels = $this->labelTrunkChannels($mgr->tenantChannels($tid, 30), $tid);
            }
        } catch (\Throwable $e) {
            \App\Core\Logger::info('Live calls feed: Asterisk unavailable', ['error' => $e->getMessage()]);
        }

        return $this->json([
            'online' => $online,
            'count' => self::activeCallCount($channels),
            'channels' => array_values($channels),
            'recent_calls' => $this->recentCalls($tid),
        ]);
    }

    /** Count bridged channel legs as one call while retaining unbridged ringing legs. */
    public static function activeCallCount(array $channels): int
    {
        $count = count($channels);
        if ($count === 0) return 0;
        $parents = range(0, $count - 1);
        $find = static function (int $index) use (&$parents, &$find): int {
            if ($parents[$index] !== $index) $parents[$index] = $find($parents[$index]);
            return $parents[$index];
        };
        $union = static function (int $left, int $right) use (&$parents, $find): void {
            $left = $find($left); $right = $find($right);
            if ($left !== $right) $parents[$right] = $left;
        };
        $seen = [];
        foreach (array_values($channels) as $index => $channel) {
            foreach (['linkedid', 'bridgeid'] as $field) {
                $value = (string) ($channel[$field] ?? '');
                if ($value === '') continue;
                $key = $field . ':' . $value;
                if (isset($seen[$key])) $union($index, $seen[$key]);
                else $seen[$key] = $index;
            }
            $name = (string) ($channel['name'] ?? '');
            if ($name !== '') $seen['name:' . $name] = $index;
        }
        foreach (array_values($channels) as $index => $channel) {
            $peer = (string) ($channel['bridged'] ?? '');
            if ($peer !== '' && isset($seen['name:' . $peer])) $union($index, $seen['name:' . $peer]);
        }
        $groups = [];
        for ($index = 0; $index < $count; $index++) $groups[$find($index)] = true;
        return count($groups);
    }

    /** Recent tenant CDR rows formatted once for both initial and live UI. */
    private function recentCalls(?int $tenantId): array
    {
        if (!$tenantId) {
            return [];
        }
        return array_map(static function (array $call): array {
            return [
                'time' => format_cdr_date($call['calldate'] ?? null, 'M j, g:i A'),
                'src' => (string) ($call['src'] ?? ''),
                'dst' => (string) ($call['dst'] ?? ''),
                'disposition' => (string) ($call['disposition'] ?? ''),
                'duration' => format_duration((int) ($call['billsec'] ?? 0)),
            ];
        }, $this->db()->all(
            'SELECT calldate, src, dst, disposition, billsec FROM cdr WHERE tenant_id = ? ORDER BY calldate DESC LIMIT 10',
            [$tenantId]
        ));
    }

    /** Add the configured tenant trunk name without exposing another tenant's trunk metadata. */
    private function labelTrunkChannels(array $channels, int $tenantId): array
    {
        $trunks = [];
        $sharedTrunks = [];
        foreach ($this->db()->all('SELECT id, name, host FROM trunks WHERE tenant_id = ?', [$tenantId]) as $trunk) {
            $trunks[(int) $trunk['id']] = (string) $trunk['name'];
            $host = strtolower(rtrim(preg_replace('/\s+/', '', (string) $trunk['host']) ?? '', '.'));
            if ($host !== '') {
                $sharedTrunks[substr(hash('sha256', $host), 0, 12)][] = (string) $trunk['name'];
            }
        }
        foreach ($channels as &$channel) {
            if (preg_match('#^PJSIP/trunk-(\d+)-#', (string) ($channel['name'] ?? ''), $match)) {
                $channel['trunk_name'] = $trunks[(int) $match[1]] ?? '';
            } elseif (preg_match('#^PJSIP/trunk-shared-([a-f0-9]{12})-#i', (string) ($channel['name'] ?? ''), $match)) {
                $names = array_values(array_unique($sharedTrunks[strtolower($match[1])] ?? []));
                $channel['trunk_name'] = count($names) === 1 ? $names[0] : implode(' / ', $names);
            }
        }
        unset($channel);
        return $channels;
    }

    /** POST /dashboard/switch-tenant */
    public function switchTenant(Request $request): Response
    {
        $tid = $request->int('tenant_id');
        $returnTo = $this->safeTenantSwitchReturn($request->str('return_to'));

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

        return Response::redirect($returnTo);
    }

    /** Keep tenant switches on the current same-origin GET page. */
    private function safeTenantSwitchReturn(string $returnTo): string
    {
        if ($returnTo === '' || strlen($returnTo) > 2048
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $returnTo)
            || !str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            return '/dashboard';
        }

        $parts = parse_url($returnTo);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || (($parts['path'] ?? '/') === '/dashboard/switch-tenant')) {
            return '/dashboard';
        }

        return $returnTo;
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
