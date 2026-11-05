<?php

declare(strict_types=1);

namespace App\Services\Asterisk;

use App\Core\App;

/** Records one interval whenever an extension's registration state changes. */
final class ExtensionStatusTracker
{
    /** @return array{available: bool, statuses: array<string,string>} */
    public function sample(?AsteriskManager $manager = null): array
    {
        $manager ??= new AsteriskManager();

        try {
            if (!$manager->isOnline()) {
                return ['available' => false, 'statuses' => []];
            }
            $contacts = $manager->contactDetails();
        } catch (\Throwable) {
            return ['available' => false, 'statuses' => []];
        }

        $statuses = [];
        foreach ($this->extensions() as $ext) {
            $statuses['ext-' . $ext['id']] = $this->statusFor($ext, $contacts);
        }
        $this->recordStatuses($statuses);

        return ['available' => true, 'statuses' => $statuses];
    }

    /** Persist state transitions; repeated samples extend the current interval. */
    public function recordStatuses(array $statuses): void
    {
        $db = App::getInstance()->db();
        $now = date('Y-m-d H:i:s');

        foreach ($this->extensions() as $ext) {
            $key = 'ext-' . $ext['id'];
            $status = !(int) $ext['enabled']
                ? 'Disabled'
                : (string) ($statuses[$key] ?? 'Unregistered');

            $current = $db->one(
                'SELECT * FROM extension_status_history WHERE extension_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1',
                [(int) $ext['id']]
            );
            if ($current && strcasecmp((string) $current['status'], $status) === 0) {
                $db->update('extension_status_history', (int) $current['id'], ['last_checked_at' => $now]);
                continue;
            }
            if ($current) {
                $db->update('extension_status_history', (int) $current['id'], [
                    'ended_at' => $now,
                    'last_checked_at' => $now,
                ]);
            }
            $db->insert('extension_status_history', [
                'extension_id' => (int) $ext['id'],
                'tenant_id' => (int) $ext['tenant_id'],
                'status' => $status,
                'started_at' => $now,
                'ended_at' => null,
                'last_checked_at' => $now,
            ]);
        }
    }

    /** @return array<int, array<string,mixed>> */
    private function extensions(): array
    {
        return App::getInstance()->db()->all(
            'SELECT e.id, e.tenant_id, e.enabled, e.extension, t.context AS ctx, t.domain AS tenant_domain
             FROM extensions e JOIN tenants t ON t.id = e.tenant_id ORDER BY e.id'
        );
    }

    /**
     * Registered when the phone (or a browser softphone) has a live contact.
     *
     * @param array<string,mixed> $ext
     * @param array<string,array{status:string,rtt:?float,count:int}> $contacts
     */
    private function statusFor(array $ext, array $contacts): string
    {
        foreach ($this->aorKeys($ext) as $key) {
            if (isset($contacts[$key])) {
                return 'Registered';
            }
        }
        return 'Unregistered';
    }

    /** Every AOR an extension can register as (phone + browser softphones). */
    private function aorKeys(array $ext): array
    {
        $ctx = (string) ($ext['ctx'] ?? '');
        $safeCtx = preg_replace('/[^a-zA-Z0-9_]/', '_', $ctx) ?? $ctx;
        $realm = strtolower(rtrim(trim((string) ($ext['tenant_domain'] ?? '')), '.'));
        if ($realm === '') {
            $realm = $safeCtx;
        }
        $extension = (string) $ext['extension'];

        return [
            $extension . '@' . $realm,
            $safeCtx . '_' . $extension . '-phone-web',
            $safeCtx . '_' . $extension . '-web',
        ];
    }
}
