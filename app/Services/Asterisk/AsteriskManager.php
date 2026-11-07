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
     * AOR => ['status' => string, 'rtt' => float|null, 'count' => int,
     *         'ips' => string[]].
     * RTT is only numeric once the AOR is being qualified (OPTIONS keepalive).
     * `ips` holds every distinct host address the AOR's contacts came from.
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
                $rows[$aor] = ['status' => $m[3], 'rtt' => $rtt, 'count' => 1, 'ips' => []];
            } else {
                // Multiple contacts on one AOR = multiple registered devices.
                $rows[$aor]['count']++;
                $rows[$aor]['status'] = $m[3];
                $rows[$aor]['rtt'] = $rtt ?? $rows[$aor]['rtt'];
            }

            // Remember where the phone actually registered from.
            $ip = self::contactIp($m[1]);
            if ($ip !== '' && !in_array($ip, $rows[$aor]['ips'], true)) {
                $rows[$aor]['ips'][] = $ip;
            }
        }
        return $rows;
    }

    /**
     * Pull the host address out of a PJSIP contact URI such as
     * `1001@tenant.test/sip:1001@192.0.2.10:5062` or `trunk-aor/sip:198.51.100.10`.
     * Returns '' when the host is not a literal IP address.
     */
    public static function contactIp(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return '';
        }

        // The address lives in the URI after the last "/".
        $host = str_contains($uri, '/')
            ? substr($uri, (int) strrpos($uri, '/') + 1)
            : $uri;

        // Drop the scheme (sip:, sips:, udp:, tcp: ...).
        if (preg_match('/^(?:sips?|udps?|tcps?):/i', $host, $m)) {
            $host = substr($host, strlen($m[0]));
        }

        // user@host -> host; the user part may itself contain "@".
        $at = strrpos($host, '@');
        if ($at !== false) {
            $host = substr($host, $at + 1);
        }

        $host = trim($host, "<>\t\n\r ");
        if ($host === '') {
            return '';
        }

        if (str_starts_with($host, '[')) {              // IPv6 literal: [2001:db8::1]:5060
            $end = strpos($host, ']');
            $host = $end === false ? '' : substr($host, 1, $end - 1);
        } elseif (substr_count($host, ':') === 1) {     // host:port (a bare IPv6 has many colons)
            $host = explode(':', $host, 2)[0];
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : '';
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
        return self::filterTenantChannels(
            $this->channelSnapshot(),
            $extensions,
            $realm,
            (string) $tenant['context'],
            $limit
        );
    }

    /** Filter a machine-readable snapshot by tenant endpoint or context. */
    public static function filterTenantChannels(
        array $snapshot,
        array $extensions,
        string $realm,
        string $context,
        int $limit = 25
    ): array {
        $prefixes = array_map(static fn ($row) => 'PJSIP/' . $row['extension'] . '@' . $realm . '-', $extensions);
        $identities = [];
        foreach ($snapshot as $channel) {
            $channelContext = (string) ($channel['context'] ?? '');
            $belongs = $channelContext === $context || str_starts_with($channelContext, $context . '-');
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) ($channel['name'] ?? ''), $prefix)
                    || str_starts_with((string) ($channel['bridged'] ?? ''), $prefix)) {
                    $belongs = true;
                    break;
                }
            }
            if ($belongs) {
                foreach (['linkedid', 'bridgeid'] as $field) {
                    $value = (string) ($channel[$field] ?? '');
                    if ($value !== '') $identities[$field . ':' . $value] = true;
                }
            }
        }

        // Include every leg sharing a call/bridge identity with a tenant leg,
        // even when the trunk channel itself is running in a generic context.
        $rows = [];
        foreach ($snapshot as $channel) {
            $channelContext = (string) ($channel['context'] ?? '');
            $belongs = $channelContext === $context || str_starts_with($channelContext, $context . '-');
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) ($channel['name'] ?? ''), $prefix)
                    || str_starts_with((string) ($channel['bridged'] ?? ''), $prefix)) {
                    $belongs = true;
                    break;
                }
            }
            foreach (['linkedid', 'bridgeid'] as $field) {
                $value = (string) ($channel[$field] ?? '');
                if ($value !== '' && isset($identities[$field . ':' . $value])) $belongs = true;
            }
            if ($belongs) $rows[] = $channel;
            if (count($rows) >= $limit) break;
        }
        return $rows;
    }

    /** Machine-readable Asterisk snapshot. Do not authorize from the dashboard's CLI table. */
    public function channelSnapshot(): array
    {
        $events = $this->ami->actionEvents('CoreShowChannels');
        $rows = self::parseCoreShowChannelEvents($events);
        if ($rows) return $rows;
        return self::parseConciseChannels($this->ami->command('core show channels concise'));
    }

    /** Normalize named AMI channel events; unlike concise output, positions never drift. */
    public static function parseCoreShowChannelEvents(array $events): array
    {
        $rows = [];
        foreach ($events as $event) {
            if (strcasecmp((string) ($event['Event'] ?? ''), 'CoreShowChannel') !== 0) continue;
            $name = (string) ($event['Channel'] ?? '');
            if ($name === '') continue;
            $rows[$name] = [
                'name' => $name,
                'context' => (string) ($event['Context'] ?? ''),
                'extension' => (string) ($event['Exten'] ?? ''),
                'priority' => (string) ($event['Priority'] ?? ''),
                'state' => (string) ($event['ChannelStateDesc'] ?? ''),
                'app' => (string) ($event['Application'] ?? ''),
                'data' => (string) ($event['ApplicationData'] ?? ''),
                'caller' => (string) ($event['CallerIDNum'] ?? ''),
                'caller_name' => (string) ($event['CallerIDName'] ?? ''),
                'connected' => (string) ($event['ConnectedLineNum'] ?? ''),
                'connected_name' => (string) ($event['ConnectedLineName'] ?? ''),
                'duration' => (string) ($event['Duration'] ?? ''),
                'bridged' => (string) ($event['BridgePeer'] ?? ''),
                'bridgeid' => (string) ($event['BridgeId'] ?? ''),
                'uniqueid' => (string) ($event['Uniqueid'] ?? ''),
                'linkedid' => (string) ($event['Linkedid'] ?? ''),
            ];
        }
        return $rows;
    }

    public static function parseConciseChannels(string $out): array
    {
        $rows = [];
        foreach (explode("\n", $out) as $line) {
            $fields = explode('!', trim($line));
            if (count($fields) >= 12 && preg_match('/^PJSIP\/[A-Za-z0-9_.@-]+-[a-fA-F0-9]+$/', $fields[0])) {
                $rows[$fields[0]] = [
                    'name' => $fields[0], 'context' => $fields[1],
                    'extension' => $fields[2], 'priority' => $fields[3],
                    'state' => $fields[4], 'app' => $fields[5], 'data' => $fields[6],
                    'caller' => $fields[7], 'duration' => $fields[10],
                    'bridged' => $fields[11],
                    'bridgeid' => '',
                    'uniqueid' => $fields[12] ?? '', 'linkedid' => $fields[13] ?? '',
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

    /** Registered contacts belonging only to one tenant's endpoint namespace. */
    public function tenantContacts(int $tenantId): array
    {
        $db = App::getInstance()->db();
        $tenant = $db->one('SELECT context, domain FROM tenants WHERE id = ? AND enabled = 1', [$tenantId]);
        if (!$tenant) return [];
        $realm = strtolower(rtrim(trim((string) ($tenant['domain'] ?? '')), '.')) ?: (string) $tenant['context'];
        return self::filterTenantContacts(
            $this->contacts(),
            $db->all('SELECT extension FROM extensions WHERE tenant_id = ? AND enabled = 1', [$tenantId]),
            $realm,
            (string) $tenant['context'],
            $db->all('SELECT id FROM trunks WHERE tenant_id = ? AND enabled = 1', [$tenantId])
        );
    }

    public static function filterTenantContacts(array $contacts, array $extensions, string $realm, string $context, array $trunks = []): array
    {
        $safeContext = preg_replace('/[^A-Za-z0-9_]/', '_', $context) ?: '';
        $allowed = [];
        foreach ($extensions as $extension) {
            $number = (string) $extension['extension'];
            $allowed[$number . '@' . $realm] = true;
            if ($safeContext !== '') $allowed[$safeContext . '_' . $number . '-phone-web'] = true;
        }
        foreach ($trunks as $trunk) $allowed['trunk-' . (int) $trunk['id'] . '-aor'] = true;
        return array_values(array_filter($contacts, static function (array $contact) use ($allowed): bool {
            $aor = strstr((string) ($contact['uri'] ?? ''), '/', true);
            return isset($allowed[$aor === false ? (string) ($contact['uri'] ?? '') : $aor]);
        }));
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
