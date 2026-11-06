<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Best-effort host resource usage for the status page: CPU, memory,
 * disk and network throughput.
 *
 * CPU/memory/network come from Linux `/proc`; disk comes from the
 * filesystem. Every section degrades to null when it cannot be read
 * (development on Windows, restricted container, unreadable mount), so
 * the status page always renders something instead of failing.
 */
final class ServerStats
{
    /**
     * Snapshot of the four resource groups.
     *
     * @return array{
     *     cpu: float|null,
     *     memory: array{total:int,used:int,available:int,percent:float}|null,
     *     disk: array{total:int,free:int,used:int,percent:float}|null,
     *     network: array{rx:float,tx:float}|null
     * }
     */
    public function sample(float $windowSeconds = 0.25): array
    {
        // One shared sampling window keeps the page to a single short pause
        // instead of sleeping once per counter.
        $start    = hrtime(true);
        $cpuBefore = self::parseCpuLine($this->read('/proc/stat'));
        $netBefore = self::parseNetDev($this->read('/proc/net/dev'));

        if (($cpuBefore !== null || $netBefore !== null) && $windowSeconds > 0) {
            usleep((int) ($windowSeconds * 1_000_000));
        }

        $cpuAfter = self::parseCpuLine($this->read('/proc/stat'));
        $netAfter = self::parseNetDev($this->read('/proc/net/dev'));
        $elapsed  = (hrtime(true) - $start) / 1e9;

        $cpu = ($cpuBefore !== null && $cpuAfter !== null)
            ? self::cpuPercent($cpuBefore, $cpuAfter)
            : null;

        $network = null;
        if ($netBefore !== null && $netAfter !== null) {
            $network = self::netRates($netBefore, $netAfter, $elapsed);
        }

        return [
            'cpu'     => $cpu,
            'memory'  => self::parseMeminfo($this->read('/proc/meminfo')),
            'disk'    => $this->disk(),
            'network' => $network,
        ];
    }

    /** Root filesystem usage; null when the path is unreadable. */
    public function disk(string $path = '/'): ?array
    {
        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);
        if ($total === false || $free === false) {
            return null;
        }
        return self::diskStats((int) $total, (int) $free);
    }

    /** ------------------------------------------------------------------
     * Pure parsers (exercised directly by the smoke tests).
     * ----------------------------------------------------------------*/

    /**
     * Aggregate `cpu  user nice ...` line from /proc/stat.
     *
     * @return array{total:int,idle:int}|null
     */
    public static function parseCpuLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || !preg_match('/^cpu\d*\s/', $line . ' ')) {
            return null;
        }

        $parts = preg_split('/\s+/', $line);
        if ($parts === false || count($parts) < 5) {
            return null;
        }

        $values = array_slice($parts, 1);
        foreach ($values as $value) {
            if (!is_numeric($value)) {
                return null;
            }
        }

        $total = (int) array_sum($values);
        // idle + iowait both count as time the CPU was doing nothing.
        $idle = (int) ($values[3] ?? 0) + (int) ($values[4] ?? 0);

        return $total > 0 ? ['total' => $total, 'idle' => $idle] : null;
    }

    /** Busy percentage between two /proc/stat samples, 0–100. */
    public static function cpuPercent(array $before, array $after): ?float
    {
        $deltaTotal = (int) ($after['total'] ?? 0) - (int) ($before['total'] ?? 0);
        $deltaIdle  = (int) ($after['idle'] ?? 0) - (int) ($before['idle'] ?? 0);

        if ($deltaTotal <= 0) {
            return null;
        }

        $used = $deltaTotal - $deltaIdle;
        return round(max(0.0, min(100.0, $used / $deltaTotal * 100)), 1);
    }

    /**
     * /proc/meminfo text => usage in bytes.
     *
     * @return array{total:int,used:int,available:int,percent:float}|null
     */
    public static function parseMeminfo(string $text): ?array
    {
        $total = $available = null;

        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^MemTotal:\s+(\d+)\s*kB/i', $line, $m)) {
                $total = (int) $m[1];
            } elseif (preg_match('/^MemAvailable:\s+(\d+)\s*kB/i', $line, $m)) {
                $available = (int) $m[1];
            }
        }

        if ($total === null || $total <= 0 || $available === null) {
            return null;
        }

        $available = max(0, min($available, $total));
        $used      = $total - $available;

        return [
            'total'     => $total * 1024,
            'used'      => $used * 1024,
            'available' => $available * 1024,
            'percent'   => round($used / $total * 100, 1),
        ];
    }

    /** @return array{total:int,free:int,used:int,percent:float}|null */
    public static function diskStats(int $total, int $free): ?array
    {
        if ($total <= 0 || $free < 0) {
            return null;
        }

        $free = min($free, $total);
        $used = $total - $free;

        return [
            'total'   => $total,
            'free'    => $free,
            'used'    => $used,
            'percent' => round($used / $total * 100, 1),
        ];
    }

    /**
     * /proc/net/dev text => cumulative rx/tx bytes, loopback excluded.
     *
     * @return array{rx:int,tx:int}|null
     */
    public static function parseNetDev(string $text): ?array
    {
        $rx = 0;
        $tx = 0;
        $seen = false;

        foreach (explode("\n", $text) as $line) {
            $line  = trim($line);
            $colon = strpos($line, ':');
            if ($line === '' || $colon === false) {
                continue; // header row
            }

            $iface = trim(substr($line, 0, $colon));
            if ($iface === 'lo') {
                continue; // loopback is not real network traffic
            }

            $cols = preg_split('/\s+/', trim(substr($line, $colon + 1)));
            if ($cols === false || count($cols) < 16) {
                continue;
            }
            if (!is_numeric($cols[0]) || !is_numeric($cols[8])) {
                continue;
            }

            $rx    += (int) $cols[0];
            $tx    += (int) $cols[8];
            $seen   = true;
        }

        return $seen ? ['rx' => $rx, 'tx' => $tx] : null;
    }

    /** Bytes per second between two /proc/net/dev samples. */
    public static function netRates(array $before, array $after, float $seconds): ?array
    {
        if ($seconds <= 0) {
            return null;
        }

        $rx = ((int) ($after['rx'] ?? 0) - (int) ($before['rx'] ?? 0)) / $seconds;
        $tx = ((int) ($after['tx'] ?? 0) - (int) ($before['tx'] ?? 0)) / $seconds;

        // A negative delta means the interface counter reset mid-window.
        if ($rx < 0 || $tx < 0) {
            return null;
        }

        return ['rx' => round($rx, 1), 'tx' => round($tx, 1)];
    }

    /** Read a /proc file, returning '' when it is missing or unreadable. */
    private function read(string $path): string
    {
        if (!is_readable($path)) {
            return '';
        }
        $data = @file_get_contents($path);
        return $data === false ? '' : $data;
    }
}
