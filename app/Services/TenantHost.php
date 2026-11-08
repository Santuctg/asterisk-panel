<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\App;
use App\Core\Config;

/** Resolves the requested panel hostname and enforces tenant-domain isolation. */
final class TenantHost
{
    public static function requestHost(): string
    {
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        return self::normalize($host);
    }

    private static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('#^https?://#', '', $value) ?: $value;
        $value = preg_replace('/:\d+$/', '', $value) ?: $value;
        return rtrim($value, '.');
    }

    private static function tenantForHost(string $host): ?array
    {
        $host = self::normalize($host);
        foreach (App::getInstance()->db()->all('SELECT id, domain FROM tenants WHERE enabled = 1 AND domain IS NOT NULL') as $tenant) {
            if (self::normalize((string) $tenant['domain']) === $host) return $tenant;
        }
        return null;
    }

    public static function allows(array $user): bool
    {
        $host = self::requestHost();
        if ($host === '') { return true; } // CLI jobs and tests have no HTTP host.
        $db = App::getInstance()->db();
        $role = (string) ($user['role'] ?? '');
        $primary = self::normalize((string) Config::get('app.primary_host', ''));
        if ($primary !== '' && hash_equals($primary, $host)) {
            if ($role === 'superadmin') { return true; }
            if (empty($user['tenant_id'])) { return false; }
            return (bool) $db->scalar('SELECT COUNT(*) FROM tenants WHERE id = ? AND enabled = 1', [(int) $user['tenant_id']]);
        }

        $tenant = self::tenantForHost($host);
        if ($tenant) {
            return $role === 'superadmin' || (int) ($user['tenant_id'] ?? 0) === (int) $tenant['id'];
        }
        return false;
    }

    public static function tenantIdForRequest(): ?int
    {
        $host = self::requestHost();
        if ($host === '') { return null; }
        $primary = self::normalize((string) Config::get('app.primary_host', ''));
        if ($primary !== '' && hash_equals($primary, $host)) { return null; }
        $tenant = self::tenantForHost($host);
        return $tenant ? (int) $tenant['id'] : null;
    }
}
