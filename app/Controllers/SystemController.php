<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Services\Asterisk\SipPort;
use App\Services\UpdateService;

/**
 * System pages: live Asterisk status, "apply config", panel settings and
 * the audit log.
 */
final class SystemController extends BaseController
{
    /** GET /system/status */
    public function status(Request $request): Response
    {
        $isGlobal = $this->app->auth()->isSuperAdmin();
        $tenantId = $this->tenantId();
        $online  = false;
        $version = ['version' => 'unavailable', 'raw' => ''];
        $uptime  = '';
        $channels = [];
        $contacts = [];
        $confs   = [];
        $queues  = [];
        $extIps  = [];
        $configDir = '';
        $serverStats = ['cpu' => null, 'memory' => null, 'disk' => null, 'network' => null];

        try {
            $mgr = asterisk();
            $online = $mgr->isOnline();

            if ($online) {
                if ($isGlobal) {
                    $version  = $mgr->version();
                    $uptime   = $mgr->uptime()['raw'] ?? '';
                }
                $channels = $tenantId ? $mgr->tenantChannels($tenantId, 30) : [];
                $contacts = $tenantId && !$isGlobal ? $mgr->tenantContacts($tenantId) : $mgr->contacts();
                $confs    = $this->tenantConferences($mgr->conferences());
                $queues   = $this->tenantLiveRows($mgr->queueStatus(), 'name', 'queues', ['extension']);

                // Collect per-extension connected IP from channel snapshot.
                if ($tenantId) {
                    try {
                        foreach ($channels as $chan) {
                            $ext = (string) ($chan['extension'] ?? '');
                            if ($ext !== '' && !isset($extIps[$ext])) {
                                $ui = (string) ($chan['uniqueid'] ?? '');
                                $ip = $ui !== '' ? substr($ui, strrpos($ui, '-') + 1) : '';
                                $extIps[$ext] = $ip;
                            }
                        }
                    } catch (\Throwable $e) {
                        \App\Core\Logger::info('Status page: channel snapshot unavailable', ['error' => $e->getMessage()]);
                    }
                }
            }
        } catch (\Throwable $e) {
            \App\Core\Logger::info('Status page: Asterisk unavailable', ['error' => $e->getMessage()]);
        }

        if ($isGlobal) {
            try {
                $configDir = (new \App\Services\Asterisk\ConfigGenerator())->dir();
            } catch (\Throwable $e) {
                $configDir = '/etc/asterisk/asterisk_panel';
            }
        }

        // Host CPU / memory / disk / network. Independent of Asterisk, so it
        // is sampled even when AMI is down; every value may be null.
        if ($isGlobal) {
            try {
                $serverStats = (new \App\Services\ServerStats())->sample();
            } catch (\Throwable $e) {
                \App\Core\Logger::info('Status page: server stats unavailable', ['error' => $e->getMessage()]);
            }

        // Feed the 24-hour graph from the page load too, so history grows
        // even when JavaScript is disabled. Capped at one row a minute.
            try {
                $history = new \App\Services\ServerStatsHistory();
                $history->record($serverStats);
                $history->prune();
            } catch (\Throwable $e) {
                \App\Core\Logger::info('Status page: server history unavailable', ['error' => $e->getMessage()]);
            }

        // 24-hour history behind the resource graph. The table is created by
        // scripts/migrate.php; a panel that has not migrated yet just shows
        // the empty state.
            $serverSeries = [];
            try {
                $serverSeries = (new \App\Services\ServerStatsHistory())->series(24, 168);
            } catch (\Throwable $e) {
                \App\Core\Logger::info('Status page: server history unavailable', ['error' => $e->getMessage()]);
            }
        } else {
            $serverSeries = [];
        }

        return $this->view('system/status', [
            'online'     => $online,
            'version'    => $version,
            'uptime'     => $uptime,
            'channels'   => $channels,
            'contacts'   => $contacts,
            'conferences'=> $confs,
            'queues'     => $queues,
            'configDir'  => $configDir,
            'extIps'     => $extIps,
            'serverStats'=> $serverStats,
            'serverSeries'=> $serverSeries,
        ]);
    }

    /**
     * GET /system/server-stats — polled by the status page so the resource
     * tiles and the 24-hour graph update without a full page reload.
     */
    public function serverStats(Request $request): Response
    {
        if (!$this->app->auth()->isSuperAdmin()) {
            return $this->json([
                'current' => ['cpu' => null, 'memory' => null, 'disk' => null, 'network' => null],
                'series' => [], 'generated_at' => date('c'),
            ]);
        }
        $stats = ['cpu' => null, 'memory' => null, 'disk' => null, 'network' => null];
        try {
            $stats = (new \App\Services\ServerStats())->sample();
        } catch (\Throwable $e) {
            \App\Core\Logger::info('Server stats: sample unavailable', ['error' => $e->getMessage()]);
        }

        $history = new \App\Services\ServerStatsHistory();
        try {
            // Capped at one row a minute, and anything past 48h is dropped.
            $history->record($stats);
            $history->prune();
        } catch (\Throwable $e) {
            \App\Core\Logger::info('Server stats: history unavailable', ['error' => $e->getMessage()]);
        }

        try {
            $series = $history->series(24, 168);
        } catch (\Throwable $e) {
            $series = [];
        }

        return $this->json([
            'current'      => $stats,
            'series'       => $series,
            'generated_at' => date('c'),
        ]);
    }

    public function update(Request $request): Response
    {
        $status = []; $error = null;
        $service = new UpdateService();
        $updateJob = $service->jobStatus();
        if (in_array($updateJob['state'] ?? '', ['queued', 'running'], true)) {
            $status = [
                'current_version' => $updateJob['current_version'] ?? config('app.version', ''),
                'latest_version' => $updateJob['target_version'] ?? '',
            ];
        } else {
            try { $status = $service->status(); } catch (\Throwable $e) { $error = $e->getMessage(); }
        }
        $current = (string) ($status['current_version'] ?? config('app.version', ''));
        $latest = (string) ($status['latest_version'] ?? '');
        $hasUpdate = $current !== '' && $latest !== '' && $latest !== 'unknown' && version_compare($latest, $current, '>');
        $repo = rtrim((string) config('app.github_repo', ''), '/');
        return $this->view('system/update', [
            'status' => $status, 'error' => $error, 'hasUpdate' => $hasUpdate,
            'repo' => $repo, 'releaseUrl' => $repo . '/releases/latest', 'updateJob' => $updateJob,
        ]);
    }

    public function applyUpdate(Request $request): Response
    {
        try {
            $job = (new UpdateService())->start($this->app->auth()->id());
            $this->audit('system.update.started', 'system', null, ['target' => $job['target_version'] ?? null]);
            if (str_contains(strtolower((string) $request->header('Accept')), 'application/json')) return $this->json($job, 202);
            Flash::info('Update started. Progress will appear on this page.');
        } catch (\Throwable $e) {
            if (str_contains(strtolower((string) $request->header('Accept')), 'application/json')) return $this->json(['error' => $e->getMessage()], 500);
            Flash::error('Update failed: ' . $e->getMessage());
        }
        return Response::redirect('/system/update');
    }

    public function updateProgress(Request $request): Response
    {
        return $this->json((new UpdateService())->jobStatus());
    }

    /** POST /system/apply — regenerate config + reload Asterisk. */
    public function apply(Request $request): Response
    {
        if (!$this->app->auth()->isSuperAdmin()) {
            return Response::text('Forbidden', 403);
        }
        $feature = $request->str('feature', 'all');

        try {
            $gen = new \App\Services\Asterisk\ConfigGenerator();

            $files = $feature === 'all'
                ? $gen->generateAll()
                : $gen->generateFor($feature);

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
        $settings = $this->loadSettings($tid);
        if ($tid) {
            $tenantTimezone = $this->db()->scalar('SELECT timezone FROM tenants WHERE id = ?', [$tid]);
            $settings['timezone'] = \normalize_timezone(is_string($tenantTimezone) ? $tenantTimezone : null, 'UTC');
        }

        return $this->view('system/settings', [
            'settings' => $settings,
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
        $recordingStart = $request->str('recording_start', 'full');
        if (!in_array($recordingStart, ['answered', 'full'], true)) {
            Flash::error('The selected recording start mode is invalid.');
            return Response::redirect('/system/settings');
        }
        $timezone = $request->str('timezone', 'UTC');
        if (!\is_valid_timezone($timezone)) {
            Flash::error('The selected timezone is invalid.');
            return Response::redirect('/system/settings');
        }
        if ($tid) {
            $this->db()->update('tenants', $tid, ['timezone' => $timezone, 'updated_at' => date('Y-m-d H:i:s')]);
        } else {
            $this->saveGlobalSetting('timezone', $timezone);
        }
        date_default_timezone_set($timezone);
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
                   'provision_base_url', 'time_format', 'recording_mode', 'recording_start'];

        foreach ($keys as $key) {
            $value = $request->str($key);

            $existing = $this->db()->one(
                'SELECT id FROM settings WHERE key = ? AND (tenant_id = ? OR (tenant_id IS NULL AND ? IS NULL)) ORDER BY id DESC LIMIT 1',
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
        $this->applyToAsterisk('settings');
        if ($this->app->auth()->isSuperAdmin()) {
            Flash::success('Settings saved. SIP port configuration was regenerated; restart Asterisk to activate a changed listener port.');
        } else {
            Flash::success('Settings saved.');
        }
        return Response::redirect('/system/settings');
    }

    /** GET /system/audit */
    public function auditLog(Request $request): Response
    {
        $page = $request->page();
        $per  = $request->perPage();

        $tenantId = $this->app->auth()->isSuperAdmin() ? null : $this->tenantId();
        $where = $tenantId ? ' WHERE a.tenant_id = ?' : '';
        $params = $tenantId ? [$tenantId] : [];
        $total = (int) $this->db()->scalar('SELECT COUNT(*) FROM audit_log a' . $where, $params);
        $pages = max(1, (int) ceil($total / $per));
        $page  = min($page, $pages);
        $off   = ($page - 1) * $per;

        $rows = $this->db()->all(
            'SELECT a.*, u.username FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             ' . $where . ' ORDER BY a.id DESC LIMIT ' . (int) $per . ' OFFSET ' . (int) $off,
            $params
        );

        return $this->view('system/audit', [
            'rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total,
        ]);
    }

    /** GET /system/audit-feed — JSON tail for the live view. */
    public function auditFeed(Request $request): Response
    {
        $since = (int) $request->get('since', 0);

        $tenantId = $this->app->auth()->isSuperAdmin() ? null : $this->tenantId();
        $where = $tenantId ? ' AND a.tenant_id = ?' : '';
        $params = $tenantId ? [$since, $tenantId] : [$since];
        $rows = $this->db()->all(
            'SELECT a.*, u.username FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.id > ?' . $where . ' ORDER BY a.id DESC LIMIT 50',
            $params
        );

        return $this->json(['rows' => $rows]);
    }

    private function loadSettings(?int $tid): array
    {
        $rows = $this->db()->all(
            'SELECT key, value FROM settings WHERE tenant_id = ? OR tenant_id IS NULL
             ORDER BY CASE WHEN tenant_id IS NULL THEN 0 ELSE 1 END, id',
            [$tid]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[$r['key']] = $r['value'];
        }
        return $out;
    }

    /** Filter global AMI rows to values owned by the selected tenant. */
    private function tenantLiveRows(array $rows, string $rowKey, string $table, array $columns): array
    {
        if ($this->app->auth()->isSuperAdmin()) return $rows;
        $tenantId = $this->tenantId();
        if (!$tenantId) return [];
        $allowed = [];
        foreach ($this->db()->all('SELECT ' . implode(', ', $columns) . " FROM {$table} WHERE tenant_id = ? AND enabled = 1", [$tenantId]) as $item) {
            foreach ($columns as $column) {
                $value = trim((string) ($item[$column] ?? ''));
                if ($value !== '') $allowed[$value] = true;
            }
        }
        return array_values(array_filter($rows, static fn (array $row): bool => isset($allowed[(string) ($row[$rowKey] ?? '')])));
    }

    /** ConfBridge conference IDs are generated as "<pin>@<tenant-context>". */
    private function tenantConferences(array $rows): array
    {
        if ($this->app->auth()->isSuperAdmin()) return $rows;
        $tenantId = $this->tenantId();
        if (!$tenantId) return [];
        $tenant = $this->db()->one('SELECT context FROM tenants WHERE id = ? AND enabled = 1', [$tenantId]);
        if (!$tenant) return [];
        $context = preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $tenant['context']) ?: 'invalid';
        $allowed = [];
        foreach ($this->db()->all('SELECT pin FROM conferences WHERE tenant_id = ? AND enabled = 1', [$tenantId]) as $conference) {
            $pin = preg_replace('/[^A-Za-z0-9*#+_.@-]/', '', (string) $conference['pin']) ?: 'invalid';
            $allowed[$pin . '@' . $context] = true;
        }
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset($allowed[(string) ($row['conference'] ?? '')])
        ));
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
