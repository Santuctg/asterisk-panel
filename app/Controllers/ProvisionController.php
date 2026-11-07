<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ProvisioningService;
use App\Support\Str;

/**
 * Public device provisioning endpoint.
 *
 * Phones request their config without a session, authenticated by a random
 * per-device capability token. Route: /prov/<token>/<mac>.cfg (or .xml)
 *
 * Keep this controller minimal and side-effect free — it must not require
 * a logged-in user, and should fail closed for unknown/disabled MACs.
 */
final class ProvisionController extends BaseController
{
    /** GET /prov/{token}/{file} */
    public function serve(Request $request): Response
    {
        $file = (string) $request->params['file'];
        $token = strtolower((string) ($request->params['token'] ?? ''));

        // Accept 001565aabbcc.cfg, 001565AABBCC.xml, 00-15-65-AA-BB-CC.cfg…
        $mac = preg_replace('/\.(cfg|xml|conf)$/i', '', $file) ?? '';
        $mac = Str::macCompact($mac);

        if (!preg_match('/^[0-9a-f]{12}$/', $mac) || !preg_match('/^[0-9a-f]{64}$/', $token)) {
            return Response::text('Bad request', 400);
        }

        $svc = new ProvisioningService();
        [$body, $ctype] = $svc->serve($mac, $token);

        if ($body === '') {
            // Unknown or disabled device: log and return 404 so the phone
            // stops hammering us, but leave a trail for the admin.
            \App\Core\Logger::info('Provisioning miss', ['mac' => $mac, 'ip' => $request->ip()]);
            return Response::text('Not found', 404);
        }

        \App\Core\Logger::info('Provisioning served', ['mac' => $mac, 'ip' => $request->ip()]);

        return (new Response($body, 200, [
            'Content-Type'            => $ctype,
            'Cache-Control'           => 'no-cache, no-store, must-revalidate',
            'Pragma'                  => 'no-cache',
            'Expires'                 => '0',
        ]));
    }
}
