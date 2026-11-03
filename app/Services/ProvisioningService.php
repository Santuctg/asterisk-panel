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
 * Phones poll a URL like:
 *     http://pbx.example.com/prov/001565aabbcc.cfg
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
                'SELECT e.*, t.context AS ctx, t.name AS tenant_name, t.provision_url AS tenant_provision
                 FROM extensions e JOIN tenants t ON t.id = e.tenant_id WHERE e.id = ?',
                [$device['extension_id']]
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
    public function serve(string $mac): array
    {
        $device = $this->findByMac($mac);
        if (!$device || empty($device['enabled'])) {
            return ['', 'text/plain'];
        }

        $cacheFile = $this->cacheDir() . '/' . Str::macCompact($mac) . '.cfg';

        // Rebuild when the device row is newer than the cached file.
        $mtime  = is_file($cacheFile) ? filemtime($cacheFile) : 0;
        $stamp  = strtotime((string) ($device['updated_at'] ?: $device['created_at'])) ?: 0;

        if ($mtime < $stamp) {
            $rendered = $this->render($device);
            @file_put_contents($cacheFile, $rendered['body']);
            return [$rendered['body'], $rendered['content_type']];
        }

        $body = @file_get_contents($cacheFile);
        if ($body === false) {
            $rendered = $this->render($device);
            @file_put_contents($cacheFile, $rendered['body']);
            return [$rendered['body'], $rendered['content_type']];
        }

        $ext = $this->extensionFor($device);
        $ctype = $this->contentTypeFor((string) ($device['vendor'] ?: 'generic'));
        return [$body, $ctype];
    }

    private function extensionFor(array $device): ?array
    {
        if (empty($device['extension_id'])) {
            return null;
        }
        return $this->db->one(
            'SELECT e.*, t.context AS ctx FROM extensions e JOIN tenants t ON t.id = e.tenant_id WHERE e.id = ?',
            [$device['extension_id']]
        );
    }

    private function contentTypeFor(string $vendor): string
    {
        return match ($vendor) {
            'yealink', 'fanvil' => 'text/xml',
            'grandstream'       => 'application/xml',
            'polycom'           => 'application/octet-stream',
            default             => 'text/plain',
        };
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

        $sipUser  = $e['extension'] ?? '';
        $sipPass  = $e['secret'] ?? '';
        $ctx      = $e['ctx'] ?? 'default';
        $sipHost  = $data['server']['sip'];

        return [
            'MAC'          => $mac,
            'MAC_LOWER'    => strtolower((string) $d['mac']),
            'EXT'          => $sipUser,
            'SIP_USER'     => $sipUser,
            'SIP_PASS'     => $sipPass,
            'SIP_AUTH'     => $sipUser,
            'DOMAIN'       => $ctx,
            'SIP_SERVER'   => $sipHost,
            'SIP_PORT'     => SipPort::configured(),
            'PROXY'        => $sipHost,
            'REGISTRAR'    => $sipHost,
            'NAME'         => $e['name'] ?? ($d['description'] ?? 'Phone'),
            'CID_NAME'     => $e['cid_name'] ?? ($sipUser ? 'Ext ' . $sipUser : ''),
            'CID_NUM'      => $e['cid_number'] ?? $sipUser,
            'DISPLAY_NAME' => $e['name'] ?? ($sipUser ? 'Ext ' . $sipUser : ''),
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
; Point the phone's provisioning URL at: {$t['SIP_SERVER']}/prov/{$t['MAC_LOWER']}.cfg
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

        return $base . '/prov/' . $file;
    }
}
