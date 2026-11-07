<?php

declare(strict_types=1);

namespace App\Services\Asterisk;

use App\Core\App;
use App\Core\Config;

/** Global UDP SIP listener setting (shared by every tenant). */
final class SipPort
{
    public const DEFAULT = 5060;

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
        $value = App::getInstance()->db()->scalar(
            'SELECT value FROM settings WHERE tenant_id IS NULL AND `key` = ? ORDER BY id DESC LIMIT 1',
            ['sip_udp_port']
        );
        $port = $value === false ? (string) Config::get('asterisk.sip_port', self::DEFAULT) : (string) $value;
        return self::valid($port) ? (int) $port : self::DEFAULT;
    }

    public static function managed(string $configDir): bool
    {
        $base = rtrim($configDir, '/') . '/pjsip_base.conf';
        return is_readable($base)
            && str_contains((string) file_get_contents($base), '#include asterisk_panel/pjsip_port.conf');
    }
}
