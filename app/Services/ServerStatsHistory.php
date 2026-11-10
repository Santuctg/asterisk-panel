<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

/**
 * Rolling history of the host resource sample so the status page can draw
 * a 24-hour graph instead of only showing the current instant.
 *
 * Rows are written at most once per `$minIntervalSeconds`, which keeps the
 * table small no matter how often the page polls, and the systemd
 * `asterisk-panel-trunk-monitor.timer` keeps it filling while nobody is
 * looking at the page.
 */
final class ServerStatsHistory
{
    public const TABLE = 'server_stats_history';
    public const GAP_SECONDS = 1200;

    /**
     * Persist one sample. Returns false when a fresher row already exists
     * (so repeated calls from a polling page stay cheap).
     */
    public function record(array $stats, int $minIntervalSeconds = 60): bool
    {
        $db = App::getInstance()->db();
        $now = time();
        $nowUtc = self::utcDate($now);

        if ($minIntervalSeconds > 0) {
            // A legacy row written in a tenant-local timezone can be dated in
            // the future relative to UTC. Ignore such rows for throttling so
            // they cannot suppress sampling until wall-clock time catches up.
            $last = $db->scalar(
                'SELECT MAX(sampled_at) FROM ' . self::TABLE . ' WHERE sampled_at <= ?',
                [$nowUtc]
            );
            if (is_string($last) && $last !== '') {
                $ts = self::utcTimestamp($last);
                if ($ts !== null && ($now - $ts) < $minIntervalSeconds) {
                    return false;
                }
            }
        }

        $memory = is_array($stats['memory'] ?? null) ? $stats['memory'] : null;
        $disk   = is_array($stats['disk'] ?? null) ? $stats['disk'] : null;
        $net    = is_array($stats['network'] ?? null) ? $stats['network'] : null;

        $db->insert(self::TABLE, [
            'sampled_at'  => self::utcDate(time()),
            'cpu_percent' => is_numeric($stats['cpu'] ?? null) ? (float) $stats['cpu'] : null,
            'mem_used'    => $memory !== null ? (int) ($memory['used'] ?? 0) : null,
            'mem_total'   => $memory !== null ? (int) ($memory['total'] ?? 0) : null,
            'disk_used'   => $disk !== null ? (int) ($disk['used'] ?? 0) : null,
            'disk_total'  => $disk !== null ? (int) ($disk['total'] ?? 0) : null,
            'net_rx'      => is_numeric($net['rx'] ?? null) ? (float) $net['rx'] : null,
            'net_tx'      => is_numeric($net['tx'] ?? null) ? (float) $net['tx'] : null,
        ]);

        return true;
    }

    /** @return array<int, array<string, mixed>> raw rows, oldest first */
    public function rows(int $hours = 24): array
    {
        $now = time();
        $since = self::utcDate($now - $hours * 3600);
        $until = self::utcDate($now);

        return App::getInstance()->db()->all(
            'SELECT sampled_at, cpu_percent, mem_used, mem_total, disk_used, disk_total, net_rx, net_tx
             FROM ' . self::TABLE . '
             WHERE sampled_at >= ? AND sampled_at <= ?
             ORDER BY sampled_at ASC',
            [$since, $until]
        );
    }

    /**
     * Average each metric into at most `$buckets` buckets so a day of
     * minute-level samples renders as a light SVG path.
     *
     * @return array<int, array{t:int, cpu:float|null, mem:float|null, disk:float|null}>
     */
    public function series(int $hours = 24, int $buckets = 168): array
    {
        $rows = $this->rows($hours);
        if ($rows === []) {
            return [];
        }

        $start = self::utcTimestamp((string) $rows[0]['sampled_at']);
        $end   = self::utcTimestamp((string) $rows[count($rows) - 1]['sampled_at']);
        if ($start === null || $end === null) {
            return [];
        }

        $span    = max(1, $end - $start);
        $buckets = max(1, $buckets);
        $acc     = [];

        foreach ($rows as $row) {
            $ts = self::utcTimestamp((string) $row['sampled_at']);
            if ($ts === null) {
                continue;
            }

            $index = (int) floor(($ts - $start) / $span * $buckets);
            $index = max(0, min($buckets - 1, $index));

            if (!isset($acc[$index])) {
                $acc[$index] = ['n' => 0, 't' => 0, 'cpu' => 0.0, 'cpuN' => 0, 'mem' => 0.0, 'memN' => 0, 'disk' => 0.0, 'diskN' => 0];
            }

            $acc[$index]['n']++;
            $acc[$index]['t'] += $ts;

            if (is_numeric($row['cpu_percent'])) {
                $acc[$index]['cpu'] += (float) $row['cpu_percent'];
                $acc[$index]['cpuN']++;
            }

            $memTotal = (int) ($row['mem_total'] ?? 0);
            if ($memTotal > 0 && is_numeric($row['mem_used'])) {
                $acc[$index]['mem'] += (int) $row['mem_used'] / $memTotal * 100;
                $acc[$index]['memN']++;
            }

            $diskTotal = (int) ($row['disk_total'] ?? 0);
            if ($diskTotal > 0 && is_numeric($row['disk_used'])) {
                $acc[$index]['disk'] += (int) $row['disk_used'] / $diskTotal * 100;
                $acc[$index]['diskN']++;
            }
        }

        ksort($acc);

        $out = [];
        foreach ($acc as $bucket) {
            $out[] = [
                't'    => (int) round($bucket['t'] / max(1, $bucket['n'])),
                'cpu'  => $bucket['cpuN'] > 0 ? round($bucket['cpu'] / $bucket['cpuN'], 1) : null,
                'mem'  => $bucket['memN'] > 0 ? round($bucket['mem'] / $bucket['memN'], 1) : null,
                'disk' => $bucket['diskN'] > 0 ? round($bucket['disk'] / $bucket['diskN'], 1) : null,
            ];
        }

        return $out;
    }

    /** Drop samples older than `$keepHours`; returns the number removed. */
    public function prune(int $keepHours = 48): int
    {
        $before = self::utcDate(time() - $keepHours * 3600);
        $stmt   = App::getInstance()->db()->run('DELETE FROM ' . self::TABLE . ' WHERE sampled_at < ?', [$before]);

        return $stmt->rowCount();
    }

    private static function utcDate(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    private static function utcTimestamp(string $value): ?int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $date->getTimestamp();
    }
}
