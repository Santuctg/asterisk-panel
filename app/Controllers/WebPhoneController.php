<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\TenantHost;
use App\Services\TenantSecurity;

/** Browser WebRTC softphone. SIP secrets stay in the user's browser only. */
final class WebPhoneController extends BaseController
{
    public function index(Request $request): Response
    {
        return $this->view('web-phone/index', [
            'browserReady' => str_starts_with((string) Config::get('app.url', ''), 'https://'),
        ], 'layouts/phone');
    }

    /** Validate SIP credentials without creating a panel login session. */
    public function connect(Request $request): Response
    {
        $extension = trim($request->str('extension'));
        $secret = (string) $request->input('secret', '');
        $throttleKey = 'web-phone:' . md5($request->ip());
        $throttle = Session::throttle($throttleKey);
        if ($throttle['attempts'] >= 5 && time() - $throttle['last'] < 300) {
            return $this->json(['error' => 'Too many attempts. Wait a few minutes and try again.'], 429);
        }
        if (!preg_match('/^[0-9]{2,12}$/', $extension) || $secret === '') {
            return $this->json(['error' => 'Enter a valid extension and SIP password.'], 422);
        }

        $tenantId = TenantHost::tenantIdForRequest();
        if (!$tenantId && auth()->check()) $tenantId = $this->tenantId();
        $select = 'SELECT e.extension, e.name, e.secret, e.tenant_id, t.context, t.domain
             FROM extensions e JOIN tenants t ON t.id = e.tenant_id
             WHERE e.extension = ? AND e.enabled = 1 AND t.enabled = 1';
        $candidates = $tenantId
            ? $this->db()->all($select . ' AND e.tenant_id = ?', [$extension, $tenantId])
            : $this->db()->all($select, [$extension]);
        $matches = array_values(array_filter($candidates, static fn (array $candidate): bool =>
            hash_equals((string) $candidate['secret'], $secret)
        ));
        if (count($matches) > 1) {
            Session::throttleHit($throttleKey);
            return $this->json(['error' => 'This extension exists in multiple tenants. Open the tenant-specific PBX URL.'], 409);
        }
        $row = $matches[0] ?? null;
        $tenantId = $row ? (int) $row['tenant_id'] : $tenantId;
        if ($tenantId && (new TenantSecurity())->isBlocked($tenantId, $request->ip())) {
            return $this->json(['error' => 'Web Phone access from this IP address is blocked.'], 403);
        }
        if (!$row) {
            Session::throttleHit($throttleKey);
            return $this->json(['error' => 'SIP extension or password is incorrect.'], 401);
        }
        Session::throttleReset($throttleKey);
        $context = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $row['context']) ?: '';
        $realm = strtolower(rtrim(trim((string) ($row['domain'] ?? '')), '.')) ?: TenantHost::requestHost();
        $contacts = array_map(static fn (array $contact): array => [
            'extension' => (string) $contact['extension'],
            'name' => (string) ($contact['name'] ?: $contact['extension']),
        ], $this->db()->all(
            'SELECT extension, name FROM extensions WHERE tenant_id = ? AND enabled = 1 AND extension <> ? ORDER BY name, extension',
            [$tenantId, $extension]
        ));
        $history = array_map(static function (array $call) use ($extension): array {
            $outgoing = (string) ($call['src'] ?? '') === $extension;
            $timestamp = strtotime((string) ($call['calldate'] ?? ''));
            return [
                'id' => 'cdr:' . ((string) ($call['uniqueid'] ?: $call['id'])),
                'number' => (string) ($outgoing ? $call['dst'] : $call['src']),
                'direction' => $outgoing ? 'outgoing' : 'incoming',
                'result' => (string) ($call['disposition'] ?: 'UNKNOWN'),
                'duration' => (int) ($call['billsec'] ?? 0),
                'time' => $timestamp ? date(DATE_ATOM, $timestamp) : (string) $call['calldate'],
            ];
        }, $this->db()->all(
            'SELECT id, calldate, src, dst, disposition, billsec, uniqueid FROM cdr
             WHERE tenant_id = ? AND (src = ? OR dst = ?) ORDER BY calldate DESC LIMIT 100',
            [$tenantId, $extension, $extension]
        ));
        return $this->json([
            'extension' => $row['extension'], 'name' => $row['name'],
            'username' => $context . '_' . $row['extension'] . '-phone-web',
            'realm' => $realm,
            'contacts' => $contacts,
            'history' => $history,
        ]);
    }
}
