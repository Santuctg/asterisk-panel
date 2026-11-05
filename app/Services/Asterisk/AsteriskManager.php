<?php

declare(strict_types=1);

namespace App\Services\Asterisk;

use App\Core\App;
use App\Core\Logger;
use App\Services\Asterisk\AmiClient;

/**
 * High-level Asterisk operations used by the dashboard and management pages.
 *
 * Each method is defensive: if AMI is unreachable (dev machine, firewall,
 * etc.) it returns a sane empty result and logs, so the UI never fatals.
 */
final class AsteriskManager
{
    private AmiClient $ami;

    public function __construct(?AmiClient $ami = null)
    {
        $this->ami = $ami ?? new AmiClient();
    }

    public function ami(): AmiClient
    {
        return $this->ami;
    }

    public function isOnline(): bool
    {
        return $this->ami->connect();
    }

    /** ['version' => '22.0.0', 'raw' => '...'] */
    public function version(): array
    {
        $out = $this->ami->command('core show version');
        if (preg_match('/Asterisk\s+([\d.]+)/i', $out, $m)) {
            return ['version' => $m[1], 'raw' => trim($out)];
        }
        return ['version' => 'unknown', 'raw' => trim($out)];
    }

    /** ['uptime' => '...', 'since' => '...'] */
    public function uptime(): array
    {
        $out = $this->ami->command('core show uptime');
        return ['raw' => trim($out)];
    }

    public function activeChannels(): int
    {
        $out = $this->ami->command('core show channels count');
        if (preg_match('/(\d+)\s+active/', $out, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)/', $out, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    public function registeredEndpoints(): int
    {
        return count($this->contactStatuses());
    }

    /** AOR => contact status for every currently registered SIP contact. */
    public function contactStatuses(): array
    {
        return self::parseContactStatuses($this->ami->command('pjsip show contacts'));
    }

    public static function parseContactStatuses(string $out): array
    {
        $rows = [];
        foreach (explode("\n", $out) as $line) {
            if (!preg_match('/^Contact:\s+(\S+)\s+(\S+)\s+(\S+)(?:\s+(\S+))?/', trim($line), $m)) { continue; }
            if (str_starts_with($m[1], '<')) { continue; }
            $aor = strstr($m[1], '/', true) ?: $m[1];
            $rows[$aor] = $m[3];
        }
        return $rows;
    }

    /**
     * AOR => ['status' => string, 'rtt' => float|null, 'count' => int].
     * RTT is only numeric once the AOR is being qualified (OPTIONS keepalive).
     */
    public function contactDetails(): array
    {
        return self::parseContactDetails($this->ami->command('pjsip show contacts'));
    }

    public static function parseContactDetails(string $out): array
    {
        $rows = [];
        foreach (explode("\n", $out) as $line) {
            if (!preg_match('/^Contact:\s+(\S+)\s+(\S+)\s+(\S+)(?:\s+(\S+))?/', trim($line), $m)) { continue; }
            if (str_starts_with($m[1], '<')) { continue; }
            $aor = strstr($m[1], '/', true) ?: $m[1];
            $rtt = isset($m[4]) && is_numeric($m[4]) ? round((float) $m[4], 1) : null;

            if (!isset($rows[$aor])) {
                $rows[$aor] = ['status' => $m[3], 'rtt' => $rtt, 'count' => 1];
                continue;
            }
            // Multiple contacts on one AOR = multiple registered devices.
            $rows[$aor]['count']++;
            $rows[$aor]['status'] = $m[3];
            $rows[$aor]['rtt'] = $rtt ?? $rows[$aor]['rtt'];
        }
        return $rows;
    }

    /** Registration name => state for outbound SIP trunks. */
    public function outboundRegistrations(): array
    {
        return self::parseOutboundRegistrations($this->ami->command('pjsip show registrations outbound'));
    }

    public static function parseOutboundRegistrations(string $out): array
    {
        $rows = [];
        foreach (explode("\n", $out) as $line) {
            if (!preg_match('/^\s*(\S+)\/\S+\s+\S+\s+(\S+)/', $line, $m)) { continue; }
            if (str_starts_with($m[1], '<')) { continue; }
            $rows[$m[1]] = $m[2];
        }
        return $rows;
    }

    /** Live channel list for the dashboard. */
    public function channels(int $limit = 25): array
    {
        $out  = $this->ami->command('core show channels');
        $rows = [];

        foreach (explode("\n", $out) as $line) {
            if (!preg_match(
                '/^(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+"?([^"]*)"?\s+(\S+)\s+(\S+)/',
                trim($line),
                $m
            )) {
                continue;
            }

            $rows[] = [
                'name'      => $m[1],
                'context'   => $m[2],
                'extension' => $m[3],
                'priority'  => $m[4],
                'app'       => $m[5],
                'data'      => $m[6],
                'caller'    => $m[7],
                'state'     => $m[8],
                'duration'  => $m[9],
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /** Never display another tenant's endpoint channels to a tenant admin. */
    public function tenantChannels(int $tenantId, int $limit = 25): array
    {
        $db = App::getInstance()->db();
        $tenant = $db->one('SELECT context, domain FROM tenants WHERE id = ? AND enabled = 1', [$tenantId]);
        if (!$tenant) {
            return [];
        }
        $realm = strtolower(rtrim(trim((string) ($tenant['domain'] ?? '')), '.')) ?: (string) $tenant['context'];
        $extensions = $db->all('SELECT extension FROM extensions WHERE tenant_id = ? AND enabled = 1', [$tenantId]);
        $prefixes = array_map(static fn ($row) => 'PJSIP/' . $row['extension'] . '@' . $realm . '-', $extensions);
        $rows = [];
        foreach ($this->channels(200) as $channel) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($channel['name'], $prefix)) {
                    $rows[] = $channel;
                    break;
                }
            }
            if (count($rows) >= $limit) {
                break;
            }
        }
        return $rows;
    }

    /** Machine-readable Asterisk snapshot. Do not authorize from the dashboard's CLI table. */
    public function channelSnapshot(): array
    {
        return self::parseConciseChannels($this->ami->command('core show channels concise'));
    }

    public static function parseConciseChannels(string $out): array
    {
        $rows = [];
        foreach (explode("\n", $out) as $line) {
            $fields = explode('!', trim($line));
            if (count($fields) >= 12 && preg_match('/^PJSIP\/[A-Za-z0-9_.@-]+-[a-fA-F0-9]+$/', $fields[0])) {
                $rows[$fields[0]] = [
                    'name' => $fields[0], 'context' => $fields[1],
                    'caller' => $fields[7], 'duration' => $fields[10],
                    'bridged' => $fields[11],
                ];
            }
        }
        return $rows;
    }

    /** Parse `queue show` into a simple array. */
    function queueStatus(): array
    {
        $out  = $this->ami->command('queue show');
        $queues = [];
        $current = null;

        foreach (explode("\n", $out) as $line) {
            $line = trim($line);

            if (preg_match('/^Queue:\s+(\S+)/i', $line, $m)) {
                $current = ['name' => $m[1], 'members' => [], 'raw' => ''];
                $queues[] = $current;
                $idx = count($queues) - 1;
                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^(?:Member(?:\s+Alias)?):\s+(\S+)\s+.*?\((\w+)\)\s+(?:in\s+(\d+)\s+calls/i', $line, $m)
                || preg_match('/^Member(?:\s+Alias)?:\s+(\S+).*?\((\w+)\)/i', $line, $m)) {
                $queues[$idx]['members'][] = [
                    'member' => $m[1],
                    'state'  => $m[2] ?? '',
                ];
            }
        }

        return $queues;
    }

    /** Registered SIP contacts (extensions currently online). */
    public function contacts(): array
    {
        $out  = $this->ami->command('pjsip show contacts');
        $rows = [];

        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^Contact:\s+(\S+)\s+(\S+)\s+(\S+)(?:\s+(\S+))?/', trim($line), $m)
                && !str_starts_with($m[1], '<')) {
                $rows[] = [
                    'uri'    => $m[1],
                    'status' => $m[3],
                    'agent'  => '',
                ];
            }
        }

        return $rows;
    }

    /** Live conferences (ConfBridge). */
    public function conferences(): array
    {
        $out  = $this->ami->command('confbridge list');
        $rows = [];

        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^Conference\s+(\S+)\s+\S+\s+\((\d+)\s+caller\(s\)\)/i', trim($line), $m)) {
                $rows[] = ['conference' => $m[1], 'callers' => (int) $m[2]];
            }
        }

        return $rows;
    }

    /** Parked calls. */
    public function parkedCalls(): array
    {
        $out  = $this->ami->command('parkedcalls list');
        $rows = [];

        foreach (explode("\n", $out) as $line) {
            if (preg_match('/^Parking:\s+(\S+).*?Channel:\s+(\S+).*?Timeout:\s+(\S+)/i', trim($line), $m)) {
                $rows[] = ['park' => $m[1], 'channel' => $m[2], 'timeout' => $m[3]];
            }
        }

        return $rows;
    }

    /** Apply config changes to a running Asterisk. */
    public function reloadConfig(string $target = ''): array
    {
        $result = $target === ''
            ? $this->ami->reload()
            : $this->ami->reload($target);

        Logger::info('Asterisk reload issued', ['target' => $target ?: 'all', 'response' => $result['Response'] ?? '']);
        return $result;
    }

    /** Reload everything our panel manages, in dependency order. */
    public function reloadManaged(): array
    {
        return [
            'pjsip'     => $this->ami->command('module reload res_pjsip.so'),
            'dialplan'  => $this->ami->command('dialplan reload'),
            'voicemail' => $this->ami->command('module reload app_voicemail.so'),
            'queues'    => $this->ami->command('module reload app_queue.so'),
            'parking'   => $this->ami->command('module reload res_parking.so'),
            'moh'       => $this->ami->command('module reload res_musiconhold.so'),
            'confbridge'=> $this->ami->command('module reload app_confbridge.so'),
        ];
    }
}
