<?php

declare(strict_types=1);

namespace App\Services\Asterisk;

use App\Core\App;
use App\Core\Config;

/** Global SIP transport settings shared by every tenant. */
final class SipPort
{
    public const DEFAULT = 5060;
    public const TCP_DEFAULT = 5060;
    public const TLS_DEFAULT = 5061;
    public const TLS_CERT = '/etc/asterisk/keys/asterisk-panel.crt';
    public const TLS_KEY = '/etc/asterisk/keys/asterisk-panel.key';

    public static function valid(string $port): bool
    {
        if (!preg_match('/^[0-9]{1,5}$/D', $port)) {
            return false;
        }
        $number = (int) $port;
        // RTP uses the default 10000–20000 UDP range on this installation.
        return $number >= 1024 && $number <= 65535 && ($number < 10000 || $number > 20000);
    }

    public static function configured(): int
    {
        $value = self::value('sip_udp_port');
        $port = $value === false ? (string) Config::get('asterisk.sip_port', self::DEFAULT) : (string) $value;
        return self::valid($port) ? (int) $port : self::DEFAULT;
    }

    public static function settings(): array
    {
        $tcp = self::value('sip_tcp_port');
        $tls = self::value('sip_tls_port');
        return [
            'udp_port' => self::configured(),
            'tcp_enabled' => self::enabled('sip_tcp_enabled', true),
            'tcp_port' => self::valid((string) $tcp) ? (int) $tcp : self::TCP_DEFAULT,
            'tls_enabled' => self::enabled('sip_tls_enabled', true),
            'tls_port' => self::valid((string) $tls) ? (int) $tls : self::TLS_DEFAULT,
            'tls_cert' => self::TLS_CERT,
            'tls_key' => self::TLS_KEY,
        ];
    }

    private static function value(string $key): mixed
    {
        try {
            return App::getInstance()->db()->scalar(
                'SELECT value FROM settings WHERE tenant_id IS NULL AND `key` = ? ORDER BY id DESC LIMIT 1',
                [$key]
            );
        } catch (\Throwable) {
            return false;
        }
    }

    private static function enabled(string $key, bool $default): bool
    {
        $value = self::value($key);
        return $value === false ? $default : (string) $value === '1';
    }

    public static function managed(string $configDir): bool
    {
        $base = rtrim($configDir, '/') . '/pjsip_base.conf';
        return is_readable($base)
            && str_contains((string) file_get_contents($base), '#include asterisk_panel/pjsip_port.conf');
    }
}
