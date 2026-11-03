<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/** Browser WebRTC softphone. SIP secrets stay in the user's browser only. */
final class WebPhoneController extends BaseController
{
    public function index(Request $request): Response
    {
        $tid = $this->tenantId();
        $tenant = $tid ? $this->db()->one('SELECT context FROM tenants WHERE id = ? AND enabled = 1', [$tid]) : null;
        $extensions = $tid ? $this->db()->all(
            'SELECT extension, name FROM extensions WHERE tenant_id = ? AND enabled = 1 ORDER BY extension', [$tid]
        ) : [];

        return $this->view('web-phone/index', [
            'extensions' => $extensions,
            'context' => $tenant ? (preg_replace('/[^A-Za-z0-9_]/', '_', $tenant['context']) ?: '') : '',
            'browserReady' => str_starts_with((string) Config::get('app.url', ''), 'https://'),
        ]);
    }
}
