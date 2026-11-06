<?php

declare(strict_types=1);

namespace App\Services\Asterisk;

use App\Core\App;

/** Dedicated, restricted WebRTC credential for an authorized panel operator. */
final class MonitorCredential
{
    public function forActor(int $tenantId, string $context, int $actorId): array
    {
        $db = App::getInstance()->db();
        $key = 'active_calls_monitor_secret_' . $actorId;
        $secret = $db->scalar(
            'SELECT value FROM settings WHERE tenant_id = ? AND key = ? ORDER BY id DESC LIMIT 1',
            [$tenantId, $key]
        );
        if (!is_string($secret) || strlen($secret) < 32) {
            $secret = bin2hex(random_bytes(24));
            $db->insert('settings', [
                'tenant_id' => $tenantId, 'key' => $key, 'value' => $secret,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $safeContext = preg_replace('/[^a-zA-Z0-9_]/', '_', $context) ?: 'tenant';
        return ['username' => $safeContext . '_monitor_' . $actorId . '-web', 'password' => $secret];
    }
}
