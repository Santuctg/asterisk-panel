<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\App;

final class TenantSecurity
{
    public function isBlocked(int $tenantId, string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) { return false; }
        return (int)App::getInstance()->db()->scalar(
            'SELECT COUNT(*) FROM tenant_login_bans WHERE tenant_id = ? AND ip = ?', [$tenantId,$ip]
        ) > 0;
    }
}
