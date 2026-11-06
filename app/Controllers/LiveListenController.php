<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/** Listen-only ChanSpy delivered to a dedicated browser identity. */
final class LiveListenController extends BaseController
{
    public function index(Request $request): Response
    {
        $tid = $this->scopedTenantId();
        $tenant = $tid ? $this->db()->one('SELECT context, domain FROM tenants WHERE id = ? AND enabled = 1', [$tid]) : null;
        $context = $tenant ? ($this->uniqueEndpointContext((int) $tid, $tenant['context']) ?? '') : '';
        $realm = $tenant ? (strtolower(rtrim(trim((string) ($tenant['domain'] ?? '')), '.')) ?: (string) $tenant['context']) : '';
        $allowed = [];
        foreach ($tid ? $this->db()->all('SELECT extension FROM extensions WHERE tenant_id = ? AND enabled = 1', [$tid]) : [] as $ext) {
            $allowed[$ext['extension'] . '@' . $realm] = true;
        }
        $channels = [];
        try {
            foreach (asterisk()->channelSnapshot() as $name => $channel) {
                if (($channel['bridgeid'] ?? '') === '' && ($channel['bridged'] ?? '') === '') {
                    continue;
                }
                $endpoint = substr($name, strlen('PJSIP/'));
                $identity = substr($endpoint, 0, strrpos($endpoint, '-'));
                if (isset($allowed[$identity])) {
                    $channel['listen_extension'] = substr($identity, 0, -strlen('@' . $realm));
                    $channels[] = $channel;
                }
            }
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Live listen listing failed', ['error' => $e->getMessage()]);
        }

        $url = (string) Config::get('app.url', '');
        $browserReady = str_starts_with($url, 'https://') && $context !== '';
        return $this->view('live-listen/index', compact('channels', 'context', 'browserReady'));
    }

    public function start(Request $request): Response
    {
        $tid = $this->scopedTenantId();
        $tenant = $tid ? $this->db()->one('SELECT context, domain FROM tenants WHERE id = ? AND enabled = 1', [$tid]) : null;
        $target = $request->str('channel');
        if (!$tenant
            || !preg_match('/^PJSIP\/[A-Za-z0-9_.@-]+-[a-fA-F0-9]+$/', $target)) {
            return Response::json(['error' => 'Invalid request'], 400);
        }
        if (!str_starts_with((string) Config::get('app.url', ''), 'https://')) {
            return Response::json(['error' => 'Browser listening requires HTTPS'], 400);
        }

        $ctx = $this->uniqueEndpointContext((int) $tid, $tenant['context']);
        if ($ctx === null) {
            return Response::json(['error' => 'Tenant endpoint namespace is ambiguous'], 409);
        }
        $realm = strtolower(rtrim(trim((string) ($tenant['domain'] ?? '')), '.')) ?: (string) $tenant['context'];
        $prefix = 'PJSIP/';
        if (!str_starts_with($target, $prefix) || !str_contains($target, '-')) {
            return Response::json(['error' => 'Call not in this tenant'], 403);
        }
        $endpoint = substr($target, strlen($prefix));
        $dash = strrpos($endpoint, '-');
        $identity = substr($endpoint, 0, $dash);
        $suffix = '@' . $realm;
        if (!str_ends_with($identity, $suffix)) {
            return Response::json(['error' => 'Call not in this tenant'], 403);
        }
        $targetExt = substr($identity, 0, -strlen($suffix));
        $extension = $targetExt !== '' ? $this->db()->one(
            'SELECT id FROM extensions WHERE tenant_id = ? AND extension = ? AND enabled = 1', [$tid, $targetExt]
        ) : null;
        if (!$extension || str_ends_with($targetExt, '-web')) {
            return Response::json(['error' => 'Call cannot be monitored'], 403);
        }

        try {
            $mgr = asterisk();
            $snapshot = $mgr->channelSnapshot(); // revalidate against a fresh AMI snapshot
            if (!isset($snapshot[$target])
                || (($snapshot[$target]['bridgeid'] ?? '') === '' && ($snapshot[$target]['bridged'] ?? '') === '')) {
                return Response::json(['error' => 'Call has ended or is not connected'], 409);
            }
            $device = $ctx . '_' . $targetExt . '-web';
            $reply = $mgr->ami()->action('Originate', [
                'Channel' => 'PJSIP/' . $device,
                'Application' => 'ChanSpy',
                'Data' => $target . ',qE', // full channel name; quiet, exit on hangup; no whisper/barge
                'Timeout' => '20000',
                'Async' => 'true',
                'CallerID' => 'Live Listen <0000>',
            ]);
            if (($reply['Response'] ?? '') !== 'Success') {
                return Response::json(['error' => 'Asterisk rejected the listen request'], 502);
            }
            $this->audit('call.listen', 'extension', (int) $extension['id'], [
                'tenant_id' => $tid, 'mode' => 'browser', 'channel' => $target,
            ]);
            return Response::json(['message' => 'Browser listening started.']);
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Live listen failed', ['error' => $e->getMessage()]);
            return Response::json(['error' => 'Asterisk is unavailable'], 502);
        }
    }

    private function scopedTenantId(): ?int
    {
        // Never trust an old tenant-switcher session value for tenant admins.
        return auth()->isSuperAdmin() ? $this->tenantId() : (int) ($this->user()['tenant_id'] ?? 0);
    }

    private function uniqueEndpointContext(int $tid, string $context): ?string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '_', $context);
        foreach ($this->db()->all('SELECT id, context FROM tenants WHERE id <> ?', [$tid]) as $row) {
            if ($safe === preg_replace('/[^a-zA-Z0-9_]/', '_', $row['context'])) {
                return null; // Two dialplan contexts must not share a PJSIP endpoint prefix.
            }
        }
        return $safe;
    }
}
