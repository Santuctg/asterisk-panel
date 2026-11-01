<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Human-friendly helpers that appear in views and controllers.
 */
final class Str
{
    /** Random alphanumeric string, optionally with a required charset. */
    public static function random(int $length = 16, string $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'): string
    {
        $out  = '';
        $max  = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }
        return $out;
    }

    /** Numeric-only password, good for phone/voicemail PINs. */
    public static function numeric(int $length = 6): string
    {
        return self::random($length, '0123456789');
    }

    public static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    /** 00:15:65:AA:BB:CC -> 001565aabbcc */
    public static function macCompact(string $mac): string
    {
        return strtolower(str_replace([':', '-', '.'], '', $mac));
    }

    /** 001565aabbcc -> 00:15:65:aa:bb:cc */
    public static function macFormat(string $mac): string
    {
        $compact = self::macCompact($mac);
        if (strlen($compact) !== 12) {
            return $mac;
        }
        return implode(':', str_split($compact, 2));
    }

    /** 001565aabbcc -> 001565AABBCC (Grandstream style) */
    public static function macUpper(string $mac): string
    {
        return strtoupper(self::macCompact($mac));
    }
}
