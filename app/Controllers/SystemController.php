<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Services\Asterisk\SipPort;

/**
 * System pages: live Asterisk status, "apply config", panel settings and
 * the audit log.
 */
final class SystemController extends BaseController
{
    /** GET /system/status */
    public function status(Request $request): Response
    {
        $online  = false;
        $version = ['version' => 'unavailable', 'raw' => ''];
        $uptime  = '';
        $channels = [];
        $contacts = [];
        $confs   = [];
        $queues  = [];

        try {
            $mgr = asterisk();
            $online = $mgr->isOnline();

            if ($online) {
                $version  = $mgr->version();
                $uptime   = $mgr->uptime()['raw'] ?? '';
                $channels = $this->tenantId() ? $mgr->tenantChannels($this->tenantId(), 30) : [];
                $contacts = $mgr->contacts();
                $confs    = $mgr->conferences();
                $queues   = $mgr->queueStatus();
            }
        } catch (\Throwable $e) {
            \App\Core\Logger::info('Status page: Asterisk unavailable', ['error' => $e->getMessage()]);
        }

        // Show where generated config would be written.
        $gen = new \App\Services\Asterisk\ConfigGenerator();

        return $this->view('system/status', [
            'online'     => $online,
            'version'    => $version,
            'uptime'     => $uptime,
            'channels'   => $channels,
            'contacts'   => $contacts,
            'conferences'=> $confs,
            'queues'     => $queues,
            'configDir'  => $gen->dir(),
        ]);
    }

    /** POST /system/apply — regenerate config + reload Asterisk. */
    public function apply(Request $request): Response
    {
        $feature = $request->str('feature', 'all');

        try {
            $gen = new \App\Services\Asterisk\ConfigGenerator();

            $files = $feature === 'all'
                ? $gen->generateAll()
                : array_keys($gen->generateFor($feature));

            $mgr = asterisk();
            if ($mgr->isOnline()) {
                $mgr->reloadManaged();
                $msg = 'Configuration applied and Asterisk reloaded (' . count($files) . ' files).';
            } else {
                $msg = 'Configuration written, but Asterisk AMI is unreachable — reload manually when ready. Files: '
                    . implode(', ', $files);
            }

            $this->audit('system.apply', 'system', null, ['feature' => $feature, 'files' => $files]);
            Flash::success($msg);
        } catch (\Throwable $e) {
            \App\Core\Logger::error('Apply config failed', ['error' => $e->getMessage()]);
            Flash::error('Failed to apply configuration: ' . $e->getMessage());
        }

        return Response::redirect('/system/status');
    }

    /** GET /system/settings */
    public function settings(Request $request): Response
    {
        $tid = $this->tenantId();

        return $this->view('system/settings', [
            'settings' => $this->loadSettings($tid),
            'sipPort'  => SipPort::configured(),
            'canChangeSipPort' => $this->app->auth()->isSuperAdmin(),
            'ami'      => [
                'host'     => config('asterisk.ami.host', '127.0.0.1'),
                'port'     => config('asterisk.ami.port', 5038),
                'username' => config('asterisk.ami.username', ''),
                'secret'   => config('asterisk.ami.secret', ''),
            ],
        ]);
    }

    /** POST /system/settings */
    public function saveSettings(Request $request): Response
    {
        $tid = $this->tenantId();
        $recordingMode = $request->str('recording_mode', 'off');
        if (!in_array($recordingMode, ['off', 'all', 'inbound', 'outbound'], true)) {
            Flash::error('The selected automatic recording mode is invalid.');
            return Response::redirect('/system/settings');
        }
        $sipPort = null;
        if ($this->app->auth()->isSuperAdmin()) {
            $sipPort = $request->str('sip_udp_port', (string) SipPort::DEFAULT);
            if (!SipPort::valid($sipPort)) {
                Flash::error('SIP UDP port must be 1024–65535, excluding the RTP range 10000–20000.');
                return Response::redirect('/system/settings');
            }
            $this->saveGlobalSetting('sip_udp_port', $sipPort);
        }

        $keys = ['company_name', 'company_email', 'company_phone', 'outbound_cid_name',
                  'outbound_cid_number', 'voicemail_email_subject', 'voicemail_email_body',
                  'provision_base_url', 'time_format', 'recording_mode'];

        foreach ($keys as $key) {
            $value = $request->str($key);

            $existing = $this->db()->one(
                'SELECT id FROM settings WHERE key = ? AND (tenant_id = ? OR (tenant_id IS NULL AND ? IS NULL))',
                [$key, $tid, $tid]
            );

            if ($existing) {
                $this->db()->update('settings', (int) $existing['id'], [
                    'value'      => $value,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            } else {
                $this->db()->insert('settings', [
                    'tenant_id'  => $tid,
                    'key'        => $key,
                    'value'      => $value,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }

        $this->audit('settings.update', 'settings', $tid);
        if ($this->app->auth()->isSuperAdmin()) {
            $this->applyToAsterisk('sip_port');
            Flash::success('Settings saved. SIP port configuration was regenerated; restart Asterisk to activate a changed listener port.');
        } else {
            $this->applyToAsterisk('route');
            Flash::success('Settings saved.');
        }
        return Response::redirect('/system/settings');
    }

    /** GET /system/audit */
    public function auditLog(Request $request): Response
    {
        $page = $request->page();
        $per  = $request->perPage();

        $total = (int) $this->db()->scalar('SELECT COUNT(*) FROM audit_log');
        $pages = max(1, (int) ceil($total / $per));
        $page  = min($page, $pages);
        $off   = ($page - 1) * $per;

        $rows = $this->db()->all(
            'SELECT a.*, u.username FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.id DESC LIMIT ' . (int) $per . ' OFFSET ' . (int) $off
        );

        return $this->view('system/audit', [
            'rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total,
        ]);
    }

    /** GET /system/audit-feed — JSON tail for the live view. */
    public function auditFeed(Request $request): Response
    {
        $since = (int) $request->get('since', 0);

        $rows = $this->db()->all(
            'SELECT a.*, u.username FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.id > ? ORDER BY a.id DESC LIMIT 50',
            [$since]
        );

        return $this->json(['rows' => $rows]);
    }

    private function loadSettings(?int $tid): array
    {
        $rows = $this->db()->all(
            'SELECT key, value FROM settings WHERE tenant_id = ? OR tenant_id IS NULL',
            [$tid]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = $r['value'];
        }
        return $out;
    }

    private function saveGlobalSetting(string $key, string $value): void
    {
        $existing = $this->db()->one(
            'SELECT id FROM settings WHERE key = ? AND tenant_id IS NULL ORDER BY id DESC LIMIT 1',
            [$key]
        );
        $data = ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')];
        if ($existing) {
            $this->db()->update('settings', (int) $existing['id'], $data);
            return;
        }
        $this->db()->insert('settings', [
            'tenant_id' => null, 'key' => $key, 'value' => $value,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
