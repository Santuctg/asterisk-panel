<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\TenantHost;
use App\Services\TenantSecurity;
use App\Services\WebPhoneLoginThrottle;

/** Browser WebRTC softphone with a separate, tenant-scoped phone session. */
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
        $tenantId = $this->tenantIdForWebPhoneHost() ?? TenantHost::tenantIdForRequest();
        if (!$tenantId && auth()->check()) $tenantId = $this->tenantId();
        $throttle = new WebPhoneLoginThrottle();
        $throttleKey = $throttle->key($request->ip());
        $retryAfter = $throttle->retryAfter($throttleKey);
        if ($retryAfter > 0) {
            return $this->json(['error' => 'Too many attempts. Wait a few minutes and try again.'], 429)
                ->withHeader('Retry-After', (string) $retryAfter);
        }
        if (!preg_match('/^[0-9]{2,12}$/', $extension) || $secret === '') {
            return $this->json(['error' => 'Enter a valid extension and SIP password.'], 422);
        }

        // A hostname may intentionally be both APP_PRIMARY_HOST and a tenant
        // domain. Web Phone must honor that URL's tenant before the generic
        // primary-host behavior, otherwise repeated credentials are ambiguous.
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
            return $this->json(['error' => 'This extension exists in multiple tenants. Open the tenant-specific PBX URL.'], 409);
        }
        $row = $matches[0] ?? null;
        $tenantId = $row ? (int) $row['tenant_id'] : $tenantId;
        if ($tenantId && (new TenantSecurity())->isBlocked($tenantId, $request->ip())) {
            return $this->json(['error' => 'Web Phone access from this IP address is blocked.'], 403);
        }
        if (!$row) {
            $throttle->hit($throttleKey, $request->ip(), $tenantId);
            Logger::warning('Web Phone authentication failed', ['ip' => $request->ip(), 'extension' => $extension]);
            return $this->json(['error' => 'SIP extension or password is incorrect.'], 401);
        }
        $throttle->reset($throttleKey);
        Session::regenerate();
        Session::put('web_phone_auth', [
            'tenant_id' => (int) $row['tenant_id'],
            'extension' => (string) $row['extension'],
            'authenticated_at' => time(),
        ]);

        return $this->json($this->phoneConfig($row))
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /** Resume a previously authenticated phone without storing SIP secrets in HTML/local storage. */
    public function session(Request $request): Response
    {
        $phoneAuth = Session::get('web_phone_auth');
        if (!is_array($phoneAuth)) return $this->json(['error' => 'No active Web Phone session.'], 401);

        $tenantId = (int) ($phoneAuth['tenant_id'] ?? 0);
        $extension = (string) ($phoneAuth['extension'] ?? '');
        $hostTenant = $this->tenantIdForWebPhoneHost() ?? TenantHost::tenantIdForRequest();
        if ($tenantId < 1 || $extension === '' || ($hostTenant && $hostTenant !== $tenantId)) {
            Session::forget('web_phone_auth');
            return $this->json(['error' => 'Web Phone session does not belong to this PBX.'], 401);
        }

        $row = $this->db()->one(
            'SELECT e.extension, e.name, e.secret, e.tenant_id, t.context, t.domain
             FROM extensions e JOIN tenants t ON t.id = e.tenant_id
             WHERE e.extension = ? AND e.tenant_id = ? AND e.enabled = 1 AND t.enabled = 1',
            [$extension, $tenantId]
        );
        if (!$row || (new TenantSecurity())->isBlocked($tenantId, $request->ip())) {
            Session::forget('web_phone_auth');
            return $this->json(['error' => 'Web Phone session has expired.'], 401);
        }

        return $this->json($this->phoneConfig($row) + ['password' => (string) $row['secret']])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    public function disconnect(Request $request): Response
    {
        Session::forget('web_phone_auth');
        return $this->json(['ok' => true])->withHeader('Cache-Control', 'no-store');
    }

    private function phoneConfig(array $row): array
    {
        $tenantId = (int) $row['tenant_id'];
        $extension = (string) $row['extension'];
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
        return [
            'extension' => $row['extension'], 'name' => $row['name'],
            'username' => $context . '_' . $row['extension'] . '-phone-web',
            'realm' => $realm,
            'contacts' => $contacts,
            'history' => $history,
        ];
    }

    private function tenantIdForWebPhoneHost(): ?int
    {
        $host = TenantHost::requestHost();
        if ($host === '') return null;
        foreach ($this->db()->all('SELECT id, domain FROM tenants WHERE enabled = 1 AND domain IS NOT NULL') as $tenant) {
            $domain = strtolower(rtrim(trim((string) $tenant['domain']), '.'));
            if ($domain !== '' && hash_equals($domain, $host)) return (int) $tenant['id'];
        }
        return null;
    }
}
