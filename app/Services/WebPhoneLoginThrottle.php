<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/** Database-backed Web Phone authentication throttle, independent of cookies. */
final class WebPhoneLoginThrottle
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 300;

    private \App\Core\Database $db;

    public function __construct()
    {
        $this->db = App::getInstance()->db();
    }

    public function key(string $ip): string
    {
        // Do not include the request Host: an attacker may be able to vary an
        // unrecognised Host header and must not gain a fresh attempt window.
        return hash('sha256', trim($ip));
    }

    /** Seconds until another attempt is allowed, or zero when allowed. */
    public function retryAfter(string $key): int
    {
        $row = $this->db->one('SELECT * FROM webphone_login_throttles WHERE key_hash = ?', [$key]);
        if (!$row) return 0;
        $now = time();
        $blockedUntil = (int) ($row['blocked_until'] ?? 0);
        if ($blockedUntil > $now) return $blockedUntil - $now;
        if ($now - (int) $row['window_started'] >= self::WINDOW_SECONDS) {
            $this->db->run('DELETE FROM webphone_login_throttles WHERE key_hash = ?', [$key]);
        }
        return 0;
    }

    public function hit(string $key, string $ip, ?int $tenantId): void
    {
        $now = time();
        $this->db->run(
            'DELETE FROM webphone_login_throttles WHERE blocked_until <= ? AND last_attempt < ?',
            [$now, $now - self::WINDOW_SECONDS]
        );
        $row = $this->db->one('SELECT * FROM webphone_login_throttles WHERE key_hash = ?', [$key]);
        if (!$row) {
            $this->db->insert('webphone_login_throttles', [
                'key_hash' => $key, 'ip' => $ip, 'tenant_id' => $tenantId,
                'attempts' => 1, 'window_started' => $now, 'last_attempt' => $now, 'blocked_until' => 0,
            ]);
            return;
        }
        $attempts = $now - (int) $row['window_started'] >= self::WINDOW_SECONDS
            ? 1 : (int) $row['attempts'] + 1;
        $windowStarted = $attempts === 1 ? $now : (int) $row['window_started'];
        $this->db->run(
            'UPDATE webphone_login_throttles
             SET ip = ?, tenant_id = ?, attempts = ?, window_started = ?, last_attempt = ?, blocked_until = ?
             WHERE key_hash = ?',
            [$ip, $tenantId, $attempts, $windowStarted, $now,
                $attempts >= self::MAX_ATTEMPTS ? $now + self::WINDOW_SECONDS : 0, $key]
        );
    }

    public function reset(string $key): void
    {
        $this->db->run('DELETE FROM webphone_login_throttles WHERE key_hash = ?', [$key]);
    }
}
