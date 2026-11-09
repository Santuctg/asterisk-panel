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
use App\Services\Branding;

/** Browser WebRTC softphone with a separate, tenant-scoped phone session. */
final class WebPhoneController extends BaseController
{
    public function index(Request $request): Response
    {
        if (!TenantHost::isKnownRequest()) {
            return Response::text('Unknown PBX host.', 421)->withHeader('Cache-Control', 'no-store');
        }
        return $this->view('web-phone/index', [
            'browserReady' => str_starts_with((string) Config::get('app.url', ''), 'https://'),
        ], 'layouts/phone')->withHeader('Cache-Control', 'no-store, private');
    }

    /** Validate SIP credentials without creating a panel login session. */
    public function connect(Request $request): Response
    {
        if (!TenantHost::isKnownRequest()) {
            return $this->json(['error' => 'Unknown PBX host.'], 421)->withHeader('Cache-Control', 'no-store');
        }
        $extension = trim($request->str('extension'));
        $secret = (string) $request->input('secret', '');
        $validExtension = preg_match('/^[0-9]{2,12}$/', $extension) === 1;
        $tenantId = $this->tenantIdForWebPhoneHost() ?? TenantHost::tenantIdForRequest();
        if (!$tenantId && auth()->check()) $tenantId = $this->tenantId();
        $throttle = new WebPhoneLoginThrottle();
        $throttleKey = $throttle->key($request->ip(), WebPhoneLoginThrottle::WEBPHONE, $validExtension ? $extension : '<invalid>');
        $retryAfter = $throttle->retryAfter($throttleKey);
        if ($retryAfter > 0) {
            return $this->json(['error' => 'Too many attempts. Wait a few minutes and try again.'], 429)
                ->withHeader('Retry-After', (string) $retryAfter);
        }
        if (!$validExtension || $secret === '') {
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
        if (!TenantHost::isKnownRequest()) {
            return $this->json(['error' => 'Unknown PBX host.'], 421)->withHeader('Cache-Control', 'no-store');
        }
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

    /** Tenant-scoped live registration state for Web Phone contacts. */
    public function presence(Request $request): Response
    {
        if (!TenantHost::isKnownRequest()) {
            return $this->json(['error' => 'Unknown PBX host.'], 421)->withHeader('Cache-Control', 'no-store');
        }
        $phoneAuth = Session::get('web_phone_auth');
        if (!is_array($phoneAuth)) {
            return $this->json(['error' => 'No active Web Phone session.'], 401)->withHeader('Cache-Control', 'no-store');
        }

        $tenantId = (int) ($phoneAuth['tenant_id'] ?? 0);
        $extension = (string) ($phoneAuth['extension'] ?? '');
        $hostTenant = $this->tenantIdForWebPhoneHost() ?? TenantHost::tenantIdForRequest();
        if ($tenantId < 1 || $extension === '' || ($hostTenant && $hostTenant !== $tenantId)
            || (new TenantSecurity())->isBlocked($tenantId, $request->ip())) {
            Session::forget('web_phone_auth');
            return $this->json(['error' => 'Web Phone session has expired.'], 401)->withHeader('Cache-Control', 'no-store');
        }

        $tenant = $this->db()->one(
            'SELECT t.context, t.domain FROM tenants t
             JOIN extensions e ON e.tenant_id = t.id
             WHERE t.id = ? AND t.enabled = 1 AND e.extension = ? AND e.enabled = 1',
            [$tenantId, $extension]
        );
        $extensions = $this->db()->all(
            'SELECT extension FROM extensions WHERE tenant_id = ? AND enabled = 1 AND extension <> ? ORDER BY extension',
            [$tenantId, $extension]
        );
        if (!$tenant) {
            Session::forget('web_phone_auth');
            return $this->json(['error' => 'Web Phone session has expired.'], 401)->withHeader('Cache-Control', 'no-store');
        }

        $available = false;
        $states = array_fill_keys(array_column($extensions, 'extension'), null);
        try {
            $manager = asterisk();
            $available = $manager->isOnline();
            if ($available) {
                $realm = strtolower(rtrim(trim((string) ($tenant['domain'] ?? '')), '.'))
                    ?: (string) ($tenant['context'] ?? '');
                $states = self::extensionPresence(
                    $extensions,
                    $manager->contactStatuses(),
                    $realm,
                    (string) ($tenant['context'] ?? '')
                );
            }
        } catch (\Throwable $e) {
            Logger::info('Web Phone presence unavailable', ['error' => $e->getMessage()]);
        }

        return $this->json(['available' => $available, 'presence' => $states])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    /** Map only the supplied tenant extensions; desk or browser AOR means online. */
    public static function extensionPresence(array $extensions, array $contacts, string $realm, string $context): array
    {
        $context = preg_replace('/[^a-zA-Z0-9_]/', '_', $context) ?: '';
        $states = [];
        foreach ($extensions as $extension) {
            $number = (string) ($extension['extension'] ?? '');
            if ($number === '') continue;
            $states[$number] = isset($contacts[$number . '@' . $realm])
                || isset($contacts[$context . '_' . $number . '-phone-web']);
        }
        return $states;
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
            'kind' => 'extension',
        ], $this->db()->all(
            'SELECT extension, name FROM extensions WHERE tenant_id = ? AND enabled = 1 AND extension <> ? ORDER BY name, extension',
            [$tenantId, $extension]
        ));
        $knownNumbers = array_fill_keys(array_column($contacts, 'extension'), true);
        foreach ($this->db()->all('SELECT phone, name FROM contacts WHERE tenant_id = ? ORDER BY name, phone', [$tenantId]) as $contact) {
            $phone = (string) $contact['phone'];
            if (isset($knownNumbers[$phone])) continue;
            $contacts[] = ['extension' => $phone, 'name' => (string) $contact['name'], 'kind' => 'contact'];
            $knownNumbers[$phone] = true;
        }
        $history = array_map(static function (array $call) use ($extension): array {
            $outgoing = (string) ($call['src'] ?? '') === $extension;
            return [
                'id' => 'cdr:' . ((string) ($call['uniqueid'] ?: $call['id'])),
                'number' => (string) ($outgoing ? $call['dst'] : $call['src']),
                'direction' => $outgoing ? 'outgoing' : 'incoming',
                'result' => (string) ($call['disposition'] ?: 'UNKNOWN'),
                'duration' => (int) ($call['billsec'] ?? 0),
                'time' => format_cdr_date((string) ($call['calldate'] ?? ''), DATE_ATOM),
            ];
        }, $this->db()->all(
            'SELECT id, calldate, src, dst, disposition, billsec, uniqueid FROM cdr
             WHERE tenant_id = ? AND (src = ? OR dst = ?) ORDER BY calldate DESC LIMIT 100',
            [$tenantId, $extension, $extension]
        ));
        $voicemailEnabled = (int) $this->db()->scalar(
            'SELECT COUNT(*) FROM voicemail v
             JOIN extensions e ON e.id = v.extension_id
             WHERE v.tenant_id = ? AND e.tenant_id = ? AND e.extension = ? AND v.enabled = 1',
            [$tenantId, $tenantId, $extension]
        ) > 0;
        return [
            'extension' => $row['extension'], 'name' => $row['name'],
            'username' => $context . '_' . $row['extension'] . '-phone-web',
            'realm' => $realm,
            'contacts' => $contacts,
            'history' => $history,
            'voicemailEnabled' => $voicemailEnabled,
            'branding' => Branding::current($tenantId),
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
