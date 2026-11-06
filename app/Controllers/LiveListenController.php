<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/** Tenant-scoped listen-only ChanSpy delivered to a browser or SIP extension. */
final class LiveListenController extends BaseController
{
    public function legacy(Request $request): Response
    {
        return Response::redirect('/active-calls');
    }

    public function index(Request $request): Response
    {
        $tid = $this->scopedTenantId();
        $tenant = $tid ? $this->db()->one('SELECT context, domain FROM tenants WHERE id = ? AND enabled = 1', [$tid]) : null;
        $context = $tenant ? ($this->uniqueEndpointContext((int) $tid, $tenant['context']) ?? '') : '';
        $realm = $tenant ? (strtolower(rtrim(trim((string) ($tenant['domain'] ?? '')), '.')) ?: (string) $tenant['context']) : '';
        $extensions = $tid ? $this->db()->all(
            'SELECT extension, name FROM extensions WHERE tenant_id = ? AND enabled = 1 ORDER BY extension', [$tid]
        ) : [];
        $channels = $this->connectedChannels((int) $tid, $realm, (string) ($tenant['context'] ?? ''), $extensions);

        $url = (string) Config::get('app.url', '');
        $browserReady = str_starts_with($url, 'https://') && $context !== '';
        return $this->view('live-listen/index', compact('channels', 'extensions', 'context', 'browserReady'));
    }

    /** Polled by the page so connected calls appear and disappear without reload. */
    public function calls(Request $request): Response
    {
        $tid = $this->scopedTenantId();
        $tenant = $tid ? $this->db()->one('SELECT context, domain FROM tenants WHERE id = ? AND enabled = 1', [$tid]) : null;
        if (!$tenant) return $this->json(['calls' => []]);
        $realm = strtolower(rtrim(trim((string) ($tenant['domain'] ?? '')), '.')) ?: (string) $tenant['context'];
        $extensions = $this->db()->all(
            'SELECT extension, name FROM extensions WHERE tenant_id = ? AND enabled = 1 ORDER BY extension', [$tid]
        );
        return $this->json(['calls' => $this->connectedChannels((int) $tid, $realm, (string) $tenant['context'], $extensions)]);
    }

    public function start(Request $request): Response
    {
        $tid = $this->scopedTenantId();
        $tenant = $tid ? $this->db()->one('SELECT context, domain FROM tenants WHERE id = ? AND enabled = 1', [$tid]) : null;
        $target = $request->str('channel');
        $mode = $request->str('mode') === 'extension' ? 'extension' : 'browser';
        if (!$tenant
            || !preg_match('/^PJSIP\/[A-Za-z0-9_.@-]+-[a-fA-F0-9]+$/', $target)) {
            return Response::json(['error' => 'Invalid request'], 400);
        }
        if ($mode === 'browser' && !str_starts_with((string) Config::get('app.url', ''), 'https://')) {
            return Response::json(['error' => 'Browser listening requires HTTPS'], 400);
        }

        $ctx = $mode === 'browser' ? $this->uniqueEndpointContext((int) $tid, $tenant['context']) : '';
        if ($mode === 'browser' && $ctx === null) return Response::json(['error' => 'Tenant endpoint namespace is ambiguous'], 409);
        $realm = strtolower(rtrim(trim((string) ($tenant['domain'] ?? '')), '.')) ?: (string) $tenant['context'];
        $prefix = 'PJSIP/';
        if (!str_starts_with($target, $prefix) || !str_contains($target, '-')) {
            return Response::json(['error' => 'Call not in this tenant'], 403);
        }
        $endpoint = substr($target, strlen($prefix));
        $dash = strrpos($endpoint, '-');
        $identity = substr($endpoint, 0, $dash);
        $targetExt = $this->targetExtension($identity, $realm, (string) $tenant['context']);
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
            if ($mode === 'extension') {
                $listenerExt = $request->str('listener_extension');
                $listener = $listenerExt !== '' ? $this->db()->one(
                    'SELECT id FROM extensions WHERE tenant_id = ? AND extension = ? AND enabled = 1', [$tid, $listenerExt]
                ) : null;
                if (!$listener || $listenerExt === $targetExt) {
                    return Response::json(['error' => 'Select another active tenant extension for listening'], 422);
                }
                $originateChannel = 'PJSIP/' . $listenerExt . '@' . $realm;
            } else {
                $listenerExt = $targetExt;
                $listener = $extension;
                $originateChannel = 'PJSIP/' . $ctx . '_' . $targetExt . '-web';
            }
            $reply = $mgr->ami()->action('Originate', [
                'Channel' => $originateChannel,
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
                'tenant_id' => $tid, 'mode' => $mode, 'listener_extension' => $listenerExt, 'channel' => $target,
            ]);
            return Response::json(['message' => $mode === 'browser'
                ? 'Browser listening started.'
                : 'Answer extension ' . $listenerExt . ' to start listening.']);
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

    /** @param array<int,array<string,mixed>> $extensions */
    private function connectedChannels(int $tid, string $realm, string $context, array $extensions): array
    {
        if ($tid <= 0 || $realm === '') return [];
        $allowed = [];
        $safeContext = preg_replace('/[^a-zA-Z0-9_]/', '_', $context) ?: '';
        foreach ($extensions as $ext) {
            $extension = (string) $ext['extension'];
            $allowed[$extension . '@' . $realm] = $extension;
            if ($safeContext !== '') $allowed[$safeContext . '_' . $extension . '-phone-web'] = $extension;
        }
        $channels = [];
        try {
            foreach (asterisk()->channelSnapshot() as $name => $channel) {
                if (!str_starts_with($name, 'PJSIP/')) continue;
                $endpoint = substr($name, strlen('PJSIP/'));
                $dash = strrpos($endpoint, '-');
                if ($dash === false) continue;
                $identity = substr($endpoint, 0, $dash);
                if (!isset($allowed[$identity])) continue;
                $extension = $allowed[$identity];
                $caller = (string) ($channel['caller'] ?? '');
                $connected = (string) ($channel['connected'] ?? '');
                $remote = $connected !== '' && $connected !== $extension ? $connected : $caller;
                if ($remote === '' || $remote === $extension || str_contains($remote, '-phone-web')) $remote = 'Unknown';
                $monitorable = ($channel['bridgeid'] ?? '') !== '' || ($channel['bridged'] ?? '') !== '';
                $channels[] = [
                    'name' => $name,
                    'listen_extension' => $extension,
                    'caller' => $caller,
                    'connected' => $connected,
                    'remote' => $remote,
                    'duration' => (string) ($channel['duration'] ?? '00:00:00'),
                    'status' => strtolower((string) ($channel['state'] ?? '')) === 'up' ? 'Connected' : 'Ringing',
                    'direction' => ($caller === $extension || str_contains($caller, '-phone-web')) ? 'Outbound' : 'Inbound',
                    'device' => str_ends_with($identity, '-phone-web') ? 'Web Phone' : 'Desk Phone',
                    'monitorable' => $monitorable,
                ];
            }
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Live listen listing failed', ['error' => $e->getMessage()]);
        }
        return $channels;
    }

    private function targetExtension(string $identity, string $realm, string $context): string
    {
        $suffix = '@' . $realm;
        if (str_ends_with($identity, $suffix)) return substr($identity, 0, -strlen($suffix));
        $safeContext = preg_replace('/[^a-zA-Z0-9_]/', '_', $context) ?: '';
        $prefix = $safeContext . '_';
        $webSuffix = '-phone-web';
        if ($safeContext !== '' && str_starts_with($identity, $prefix) && str_ends_with($identity, $webSuffix)) {
            return substr($identity, strlen($prefix), -strlen($webSuffix));
        }
        return '';
    }
}
