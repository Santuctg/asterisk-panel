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
        $tid = TenantHost::tenantIdForRequest();
        if (!$tid && auth()->check()) $tid = $this->tenantId();
        $tenant = $tid ? $this->db()->one('SELECT context FROM tenants WHERE id = ? AND enabled = 1', [$tid]) : null;

        return $this->view('web-phone/index', [
            'tenantDetected' => $tenant !== null,
            'browserReady' => str_starts_with((string) Config::get('app.url', ''), 'https://'),
        ], 'layouts/phone');
    }

    /** Validate SIP credentials without creating a panel login session. */
    public function connect(Request $request): Response
    {
        $extension = trim($request->str('extension'));
        $secret = (string) $request->input('secret', '');
        $domain = strtolower(rtrim(trim($request->str('tenant_domain')), '.'));
        $throttleKey = 'web-phone:' . md5($request->ip());
        $throttle = Session::throttle($throttleKey);
        if ($throttle['attempts'] >= 5 && time() - $throttle['last'] < 300) {
            return $this->json(['error' => 'Too many attempts. Wait a few minutes and try again.'], 429);
        }
        if (!preg_match('/^[0-9]{2,12}$/', $extension) || $secret === '') {
            return $this->json(['error' => 'Enter a valid extension and SIP password.'], 422);
        }

        $tenantId = TenantHost::tenantIdForRequest();
        if (!$tenantId && $domain !== '') {
            foreach ($this->db()->all('SELECT id, domain FROM tenants WHERE enabled = 1') as $candidate) {
                $candidateDomain = strtolower(rtrim(trim((string) ($candidate['domain'] ?? '')), '.'));
                if ($candidateDomain !== '' && hash_equals($candidateDomain, $domain)) {
                    $tenantId = (int) $candidate['id'];
                    break;
                }
            }
        }
        if (!$tenantId && auth()->check()) $tenantId = $this->tenantId();
        if ($tenantId && (new TenantSecurity())->isBlocked($tenantId, $request->ip())) {
            return $this->json(['error' => 'Web Phone access from this IP address is blocked.'], 403);
        }
        $row = $tenantId ? $this->db()->one(
            'SELECT e.extension, e.name, e.secret, t.context, t.domain
             FROM extensions e JOIN tenants t ON t.id = e.tenant_id
             WHERE e.tenant_id = ? AND e.extension = ? AND e.enabled = 1 AND t.enabled = 1',
            [$tenantId, $extension]
        ) : null;
        if (!$row || !hash_equals((string) $row['secret'], $secret)) {
            Session::throttleHit($throttleKey);
            return $this->json(['error' => 'SIP extension or password is incorrect.'], 401);
        }
        Session::throttleReset($throttleKey);
        $context = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $row['context']) ?: '';
        $realm = strtolower(rtrim(trim((string) ($row['domain'] ?? '')), '.')) ?: TenantHost::requestHost();
        return $this->json([
            'extension' => $row['extension'], 'name' => $row['name'],
            'username' => $context . '_' . $row['extension'] . '-phone-web',
            'realm' => $realm,
        ]);
    }
}
