<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Config;
use App\Services\Asterisk\SipPort;
use App\Support\Str;

/**
 * Generates and serves device provisioning files.
 *
 * Phones poll a tokenized URL like:
 *     https://pbx.example.com/prov/<random-token>/001565aabbcc.cfg
 * and we render a vendor-specific config from the panel's database.
 * Files are cached under storage/provision/ and can be regenerated
 * from the Devices page ("Resync").
 */
final class ProvisioningService
{
    private \App\Core\Database $db;

    public function __construct()
    {
        $this->db = App::getInstance()->db();
    }

    public function cacheDir(): string
    {
        $dir = BASE_PATH . '/storage/provision';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** Look up a device by (compact) MAC and return its row, or null. */
    public function findByMac(string $mac): ?array
    {
        $compact = Str::macCompact($mac);
        return $this->db->one('SELECT * FROM devices WHERE mac = ?', [$compact]);
    }

    /**
     * Render the provisioning config for a device.
     * Returns [vendor => ..., body => ..., content_type => ...].
     */
    public function render(array $device): array
    {
        $ext = null;
        if (!empty($device['extension_id'])) {
            $ext = $this->db->one(
                'SELECT e.*, t.context AS ctx, t.name AS tenant_name, t.domain AS tenant_domain,
                        t.provision_url AS tenant_provision
                 FROM extensions e JOIN tenants t ON t.id = e.tenant_id
                 WHERE e.id = ? AND e.tenant_id = ?',
                [$device['extension_id'], $device['tenant_id']]
            );
        }

        $data = [
            'device'    => $device,
            'extension' => $ext,
            'tenant'    => $ext ? [
                'context' => $ext['ctx'],
                'name'    => $ext['tenant_name'],
            ] : null,
            'server' => [
                'host' => Config::get('app.url', 'http://localhost'),
                'sip'  => Config::get('asterisk.sip_host', Config::get('app.url', 'http://localhost')),
            ],
        ];

        $vendor = strtolower((string) ($device['vendor'] ?: 'generic'));

        return match ($vendor) {
            'yealink'    => $this->yealink($data),
            'polycom'    => $this->polycom($data),
            'grandstream'=> $this->grandstream($data),
            'fanvil'     => $this->fanvil($data),
            default      => $this->generic($data),
        };
    }

    /** Serve (and cache) a device config. Returns [body, content_type]. */
    public function serve(string $mac, string $token): array
    {
        $device = $this->findByMac($mac);
        if (!$device || empty($device['enabled']) || empty($device['provision_token'])
            || !hash_equals((string) $device['provision_token'], strtolower($token))) {
            return ['', 'text/plain'];
        }

        $cacheFile = $this->cacheDir() . '/' . Str::macCompact($mac) . '.cfg';
        $rendered = $this->render($device);
        $cached = @file_get_contents($cacheFile);

        // Compare actual rendered content rather than timestamps: several
        // legacy update paths do not maintain updated_at, and changes to an
        // extension secret or tenant realm must never serve stale credentials.
        if ($cached === false || !hash_equals(hash('sha256', $cached), hash('sha256', $rendered['body']))) {
            @file_put_contents($cacheFile, $rendered['body'], LOCK_EX);
        }

        return [$rendered['body'], $rendered['content_type']];
    }

    /** Load a template from resources/templates/provisioning/{vendor}.cfg */
    private function template(string $vendor): string
    {
        $path = BASE_PATH . '/resources/templates/provisioning/' . $vendor . '.cfg';
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** Substitute {{token}} placeholders. */
    private function fill(string $tpl, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $tpl = str_replace('{{' . $k . '}}', (string) $v, $tpl);
        }
        // Remove any tokens that had no value so phones don't choke on them.
        return preg_replace('/\{\{[a-z0-9_]+\}\}/i', '', $tpl) ?? $tpl;
    }

    private function tokens(array $data): array
    {
        $d   = $data['device'];
        $e   = $data['extension'];
        $mac = Str::macUpper((string) $d['mac']);

        $extension = $e['extension'] ?? '';
        $sipPass  = $e['secret'] ?? '';
        $ctx      = $e['ctx'] ?? 'default';
        $sipUser  = $extension;
        $sipHost  = (string) ($e['tenant_domain'] ?? '') ?: (string) $data['server']['sip'];
        if (str_contains($sipHost, '://')) {
            $sipHost = (string) (parse_url($sipHost, PHP_URL_HOST) ?: $sipHost);
        }

        return [
            'MAC'          => $mac,
            'MAC_LOWER'    => strtolower((string) $d['mac']),
            'EXT'          => $extension,
            'SIP_USER'     => $sipUser,
            'SIP_PASS'     => $sipPass,
            'SIP_AUTH'     => $sipUser,
            'DOMAIN'       => $sipHost,
            'SIP_SERVER'   => $sipHost,
            'SIP_PORT'     => SipPort::configured(),
            'PROXY'        => $sipHost,
            'REGISTRAR'    => $sipHost,
            'NAME'         => $e['name'] ?? ($d['description'] ?? 'Phone'),
            'CID_NAME'     => $e['cid_name'] ?? ($extension ? 'Ext ' . $extension : ''),
            'CID_NUM'      => $e['cid_number'] ?? $extension,
            'DISPLAY_NAME' => $e['name'] ?? ($extension ? 'Ext ' . $extension : ''),
            'BLF_PREFIX'   => 'x',
            'TIME_SERVER'  => Config::get('asterisk.time_server', 'pool.ntp.org'),
        ];
    }

    private function yealink(array $data): array
    {
        $tpl  = $this->template('yealink');
        $body = $this->fill($tpl, $this->tokens($data));
        return ['body' => $body, 'content_type' => 'text/xml'];
    }

    private function polycom(array $data): array
    {
        $tpl  = $this->template('polycom');
        $body = $this->fill($tpl, $this->tokens($data));
        return ['body' => $body, 'content_type' => 'application/octet-stream'];
    }

    private function grandstream(array $data): array
    {
        $tpl  = $this->template('grandstream');
        $body = $this->fill($tpl, $this->tokens($data));
        return ['body' => $body, 'content_type' => 'application/xml'];
    }

    private function fanvil(array $data): array
    {
        $tpl  = $this->template('fanvil');
        $body = $this->fill($tpl, $this->tokens($data));
        return ['body' => $body, 'content_type' => 'text/xml'];
    }

    private function generic(array $data): array
    {
        $t = $this->tokens($data);

        $body = <<<CFG
; Generic provisioning file for {$t['MAC']}
; Use the tokenized provisioning URL shown on the Devices page.
[general]
sip_server={$t['SIP_SERVER']}
sip_port={$t['SIP_PORT']}
username={$t['SIP_USER']}
password={$t['SIP_PASS']}
auth_name={$t['SIP_AUTH']}
domain={$t['DOMAIN']}
display_name={$t['DISPLAY_NAME']}
caller_id_name={$t['CID_NAME']}
caller_id_number={$t['CID_NUM']}
CFG;
        return ['body' => $body, 'content_type' => 'text/plain'];
    }

    /** The URL a phone should be pointed at for this device. */
    public function provisionUrl(array $device): string
    {
        $base  = rtrim((string) Config::get('app.url', 'http://localhost'), '/');
        $mac   = Str::macCompact((string) $device['mac']);
        $token = strtolower((string) ($device['provision_token'] ?? ''));
        $model = strtolower((string) ($device['model'] ?: ''));

        // Vendor-specific filename conventions:
        //   Yealink      -> 001565aabbcc.cfg
        //   Grandstream  -> 000b82aaXXYY.cfg
        //   Polycom      -> 001565aabbcc.cfg (or mac-address.cfg)
        //   Fanvil       -> 001565AABBCC.xml
        $file = match ($model) {
            'fanvil' => Str::macUpper($mac) . '.xml',
            default  => $mac . '.cfg',
        };

        return $base . '/prov/' . $token . '/' . $file;
    }
}
