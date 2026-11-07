<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/** Enforces one tenant dialable number across every generated feature. */
final class DialplanNumberRegistry
{
    private const TABLES = [
        'extensions', 'ring_groups', 'ivr_menus', 'queues',
        'conferences', 'parking_lots', 'time_conditions',
    ];

    public function available(int $tenantId, string $number, string $ownTable, ?int $ownId = null): bool
    {
        if (!in_array($ownTable, self::TABLES, true) || $tenantId <= 0 || $number === '') return false;
        $db = App::getInstance()->db();
        foreach (self::TABLES as $table) {
            $sql = "SELECT COUNT(*) FROM {$table} WHERE tenant_id = ? AND extension = ?";
            $params = [$tenantId, $number];
            if ($table === $ownTable && $ownId !== null) {
                $sql .= ' AND id != ?';
                $params[] = $ownId;
            }
            if ((int) $db->scalar($sql, $params) > 0) return false;
        }
        return true;
    }
}
