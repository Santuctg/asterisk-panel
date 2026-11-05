<?php

declare(strict_types=1);

namespace App\Services\Asterisk;

use App\Core\App;

/** Records one interval whenever an outbound trunk registration state changes. */
final class TrunkStatusTracker
{
    /** @return array{available: bool, statuses: array<string,string>} */
    public function sample(?AsteriskManager $manager = null): array
    {
        $db = App::getInstance()->db();
        $trunks = $db->all('SELECT id, tenant_id, enabled FROM trunks ORDER BY id');
        $manager ??= new AsteriskManager();

        try {
            if (!$manager->isOnline()) {
                return ['available' => false, 'statuses' => []];
            }
            $registrations = $manager->outboundRegistrations();
        } catch (\Throwable) {
            return ['available' => false, 'statuses' => []];
        }

        $statuses = [];
        foreach ($trunks as $trunk) {
            $key = 'trunk-' . $trunk['id'];
            $status = !(int) $trunk['enabled'] ? 'Disabled' : (string) ($registrations[$key] ?? 'Unregistered');
            $statuses[$key] = $status;
        }
        $this->recordStatuses($statuses);

        return ['available' => true, 'statuses' => $statuses];
    }

    /** Persist state transitions; repeated samples extend the current interval. */
    public function recordStatuses(array $statuses): void
    {
        $db = App::getInstance()->db();
        $trunks = $db->all('SELECT id, tenant_id, enabled FROM trunks ORDER BY id');
        $now = date('Y-m-d H:i:s');
        foreach ($trunks as $trunk) {
            $key = 'trunk-' . $trunk['id'];
            $status = !(int) $trunk['enabled'] ? 'Disabled' : (string) ($statuses[$key] ?? 'Unregistered');

            $current = $db->one(
                'SELECT * FROM trunk_status_history WHERE trunk_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1',
                [(int) $trunk['id']]
            );
            if ($current && strcasecmp((string) $current['status'], $status) === 0) {
                $db->update('trunk_status_history', (int) $current['id'], ['last_checked_at' => $now]);
                continue;
            }
            if ($current) {
                $db->update('trunk_status_history', (int) $current['id'], [
                    'ended_at' => $now,
                    'last_checked_at' => $now,
                ]);
            }
            $db->insert('trunk_status_history', [
                'trunk_id' => (int) $trunk['id'],
                'tenant_id' => (int) $trunk['tenant_id'],
                'status' => $status,
                'started_at' => $now,
                'ended_at' => null,
                'last_checked_at' => $now,
            ]);
        }
    }
}
