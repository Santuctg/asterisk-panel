<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/** Resolves tenant branding with global and application defaults as fallbacks. */
final class Branding
{
    public static function current(?int $tenantId = null): array
    {
        if ($tenantId === null) {
            $tenantId = self::requestTenantId();
        }

        $values = [];
        try {
            $rows = App::getInstance()->db()->all(
                'SELECT tenant_id, `key`, value FROM settings WHERE tenant_id IS NULL OR tenant_id = ?
                 ORDER BY CASE WHEN tenant_id IS NULL THEN 0 ELSE 1 END, id',
                [$tenantId]
            );
            foreach ($rows as $row) {
                $values[(string) $row['key']] = (string) ($row['value'] ?? '');
                if ((string) $row['key'] === 'panel_logo') $logoTenant = $row['tenant_id'] === null ? null : (int) $row['tenant_id'];
            }
        } catch (\Throwable) {
            // Fresh installs and isolated tests can render before migrations run.
        }

        $name = trim((string) ($values['panel_name'] ?? ''));
        if ($name === '') $name = (string) config('app.name', 'Asterisk Panel');
        $logo = trim((string) ($values['panel_logo'] ?? ''));

        return [
            'name' => $name,
            'logo' => $logo,
            'logo_url' => $logo !== '' ? '/branding/logo' : '',
            'logo_tenant_id' => $logoTenant ?? null,
            'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
            'tenant_id' => $tenantId,
        ];
    }

    public static function requestTenantId(): ?int
    {
        try {
            if (auth()->check()) return tenant_id();
            $phone = \App\Core\Session::get('web_phone_auth');
            if (is_array($phone) && (int) ($phone['tenant_id'] ?? 0) > 0) {
                return (int) $phone['tenant_id'];
            }
            return TenantHost::tenantIdForRequest();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function absoluteLogoPath(string $relative): ?string
    {
        if (!preg_match('#^branding/(?:global|tenant-[1-9][0-9]*)/[a-f0-9]{32}\.(?:png|jpe?g|webp)$#', $relative)) {
            return null;
        }
        $base = realpath(BASE_PATH . '/storage') ?: BASE_PATH . '/storage';
        $path = BASE_PATH . '/storage/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $real = realpath($path);
        return $real && str_starts_with($real, $base . DIRECTORY_SEPARATOR) && is_file($real) ? $real : null;
    }
}
