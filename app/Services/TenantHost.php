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
        return preg_replace('/:\d+$/', '', $host) ?: '';
    }

    public static function allows(array $user): bool
    {
        $host = self::requestHost();
        if ($host === '') { return true; } // CLI jobs and tests have no HTTP host.
        $db = App::getInstance()->db();
        $role = (string) ($user['role'] ?? '');
        $primary = strtolower((string) Config::get('app.primary_host', ''));
        if ($primary !== '' && hash_equals($primary, $host)) {
            if ($role === 'superadmin') { return true; }
            if (!in_array($role, ['user', 'admin'], true) || empty($user['tenant_id'])) { return false; }
            $domain = $db->scalar('SELECT domain FROM tenants WHERE id = ? AND enabled = 1', [(int) $user['tenant_id']]);
            return $domain === false || trim((string) $domain) === '';
        }

        $tenant = $db->one('SELECT id FROM tenants WHERE enabled = 1 AND LOWER(domain) = ?', [$host]);
        if ($tenant) {
            return in_array($role, ['user', 'admin'], true)
                && (int) ($user['tenant_id'] ?? 0) === (int) $tenant['id'];
        }
        return false;
    }
}
