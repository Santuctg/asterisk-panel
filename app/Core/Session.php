<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Thin wrapper over PHP's native sessions with flash-message support.
 */
final class Session
{
    private const FLASH_KEY = '__flash';

    public static function start(): void
    {
        if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(Config::get('app.session_name', 'asterisk_panel_session'));
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (bool) Config::get('app.secure_cookies', false),
        ]);
        session_start();

        $now = time();
        $timeout = max(900, (int) Config::get('app.session_timeout', 28800));
        $lastActivity = (int) ($_SESSION['__last_activity'] ?? 0);
        if ($lastActivity > 0 && $lastActivity < $now - $timeout) {
            $_SESSION = [];
            session_regenerate_id(true);
        } elseif ((int) ($_SESSION['__last_regenerated'] ?? 0) < $now - 900) {
            session_regenerate_id(true);
            $_SESSION['__last_regenerated'] = $now;
        }
        $_SESSION['__last_activity'] = $now;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function id(): string
    {
        return session_id() ?: '';
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
            session_destroy();
        }
    }

    // ----- Flash messages -------------------------------------------------

    public static function flash(string $type, string $message): void
    {
        $_SESSION[self::FLASH_KEY][] = ['type' => $type, 'message' => $message];
    }

    /** Retrieve and clear all flash messages. */
    public static function pullFlashes(): array
    {
        $all        = $_SESSION[self::FLASH_KEY] ?? [];
        unset($_SESSION[self::FLASH_KEY]);
        return $all;
    }

    /** Rate-limit key for login attempts. */
    public static function throttle(string $key): array
    {
        return $_SESSION['__throttle'][$key] ??= ['attempts' => 0, 'last' => 0];
    }

    public static function throttleHit(string $key): void
    {
        $_SESSION['__throttle'][$key]['attempts']++;
        $_SESSION['__throttle'][$key]['last'] = time();
    }

    public static function throttleReset(string $key): void
    {
        unset($_SESSION['__throttle'][$key]);
    }
}
