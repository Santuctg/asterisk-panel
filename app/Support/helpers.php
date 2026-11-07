<?php

declare(strict_types=1);

/**
 * Global helper functions available to controllers and views.
 * Loaded once by app/bootstrap.php.
 */

use App\Core\App;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Str;
use App\Services\Asterisk\AsteriskManager;

if (!function_exists('e')) {
    /** HTML-escape for safe output in templates. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('app')) {
    function app(): App
    {
        return App::getInstance();
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('auth')) {
    function auth(): Auth
    {
        return App::getInstance()->auth();
    }
}

if (!function_exists('asset')) {
    /** URL for a public asset, with a cache-busting version query. */
    function asset(string $path): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');
        $v    = (string) Config::get('app.version', '1');
        return $base . '/' . ltrim($path, '/') . '?v=' . urlencode($v);
    }
}

if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('redirect')) {
    function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }
}

if (!function_exists('old')) {
    /** Re-populate a form field from the previous request, if present. */
    function old(string $key, mixed $default = ''): mixed
    {
        if (preg_match('/(?:password|secret|token|pin)$/i', $key)) {
            return $default;
        }
        $old = $_SESSION['__old'] ?? [];
        return $old[$key] ?? $default;
    }
}

if (!function_exists('flash_messages')) {
    function flash_messages(): array
    {
        return Flash::pull();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return \App\Core\Csrf::field();
    }
}

// Short alias used by the existing form views.
if (!function_exists('csrf')) {
    function csrf(): string
    {
        return csrf_field();
    }
}

if (!function_exists('asterisk')) {
    function asterisk(): AsteriskManager
    {
        static $mgr = null;
        return $mgr ??= new AsteriskManager();
    }
}

if (!function_exists('tenant_id')) {
    /** Active tenant for the current session (falls back to first tenant). */
    function tenant_id(): ?int
    {
        $auth = auth();
        $user = $auth->user();

        // Only superadmins may switch tenants. A stale session must not
        // override the tenant assigned to an ordinary user or tenant admin.
        if ($user && !$auth->isSuperAdmin()) {
            return !empty($user['tenant_id']) ? (int) $user['tenant_id'] : null;
        }

        // On a tenant hostname, a superadmin is pinned to that hostname's
        // tenant. The main hostname retains the global tenant switcher.
        if ($user && $auth->isSuperAdmin()) {
            $hostTenant = \App\Services\TenantHost::tenantIdForRequest();
            if ($hostTenant) { return $hostTenant; }
        }

        $tid = $_SESSION['active_tenant'] ?? null;
        if ($tid) {
            return (int) $tid;
        }

        if ($user && !empty($user['tenant_id'])) {
            return (int) $user['tenant_id'];
        }

        // Superadmin with no explicit selection: use the first tenant.
        $first = app()->db()->scalar('SELECT id FROM tenants ORDER BY id LIMIT 1');
        return $first ? (int) $first : null;
    }
}

if (!function_exists('tenant_context')) {
    /** The Asterisk dialplan context for the active tenant. */
    function tenant_context(): string
    {
        $tid = tenant_id();
        if (!$tid) {
            return 'default';
        }
        return (string) (app()->db()->scalar('SELECT context FROM tenants WHERE id = ?', [$tid]) ?? 'default');
    }
}

if (!function_exists('format_duration')) {
    function format_duration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }
}

if (!function_exists('status_timeline')) {
    /**
     * Turn status-history rows into segments across a fixed window for the
     * availability bar. Gaps (before monitoring began) simply stay empty.
     *
     * @param array<int, array{status: string, started_at: string, ended_at?: string|null}> $events
     * @return array<int, array{left: float, width: float, status: string, title: string}>
     */
    function status_timeline(array $events, ?int $now = null, int $hours = 24): array
    {
        $now  = $now ?? time();
        $from = $now - ($hours * 3600);
        $span = $hours * 3600;
        $segments = [];

        foreach ($events as $ev) {
            $start = strtotime((string) $ev['started_at']);
            if ($start === false) {
                continue;
            }
            $end = !empty($ev['ended_at']) ? (strtotime((string) $ev['ended_at']) ?: $now) : $now;
            $start = max($start, $from);
            $end = min($end, $now);
            if ($end <= $start) {
                continue;
            }

            $left  = ($start - $from) / $span * 100;
            $width = ($end - $start) / $span * 100;
            if ($left + $width > 100) {
                $width = max(0.2, 100 - $left);
            }

            $segments[] = [
                'left'   => round($left, 3),
                'width'  => round(max(0.2, $width), 3),
                'status' => (string) $ev['status'],
                'title'  => sprintf(
                    '%s · %s → %s',
                    $ev['status'],
                    date('M j, g:i A', $start),
                    !empty($ev['ended_at']) ? date('M j, g:i A', $end) : 'now'
                ),
            ];
        }

        return $segments;
    }
}

if (!function_exists('status_timeline_class')) {
    /** CSS class for a timeline segment: up, down, or disabled. */
    function status_timeline_class(string $status): string
    {
        $normalized = strtolower($status);
        if (in_array($normalized, ['registered', 'avail', 'up'], true)) {
            return 'ok';
        }
        if ($normalized === 'disabled') {
            return 'off';
        }
        return 'down';
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $dt, string $format = 'M j, Y g:i A'): string
    {
        if (!$dt) {
            return '—';
        }
        $ts = strtotime($dt);
        return $ts ? date($format, $ts) : '—';
    }
}

if (!function_exists('format_cdr_date')) {
    /** Convert Asterisk's timezone-less CDR timestamp into the active tenant timezone. */
    function format_cdr_date(?string $dt, string $format = 'M j, Y g:i A'): string
    {
        if (!$dt) { return '—'; }
        try {
            $source = new DateTimeZone((string) config('asterisk.cdr_timezone', 'UTC'));
            $target = new DateTimeZone(date_default_timezone_get());
            return (new DateTimeImmutable($dt, $source))->setTimezone($target)->format($format);
        } catch (Throwable) {
            return format_date($dt, $format);
        }
    }
}

if (!function_exists('timezone_options')) {
    /**
     * Every timezone known to this PHP build, grouped for readable selects.
     * ALL_WITH_BC keeps backwards-compatible IANA aliases available too.
     *
     * @return array<string, array<int, array{id:string,label:string}>>
     */
    function timezone_options(): array
    {
        static $options = null;
        if ($options !== null) {
            return $options;
        }

        $groups = [];
        foreach (DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC) as $identifier) {
            $parts = explode('/', $identifier, 2);
            $group = count($parts) === 2 ? $parts[0] : 'General';
            $place = count($parts) === 2 ? $parts[1] : $identifier;
            $label = str_replace(['_', '/'], [' ', ' / '], $place);

            $groups[$group][] = [
                'id'    => $identifier,
                'label' => $label . ' (' . $identifier . ')',
            ];
        }

        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        return $options = $groups;
    }
}

if (!function_exists('is_valid_timezone')) {
    function is_valid_timezone(string $timezone): bool
    {
        static $valid = null;
        $valid ??= array_fill_keys(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
        return isset($valid[$timezone]);
    }
}

if (!function_exists('normalize_timezone')) {
    /** Convert old Asterisk UI aliases and reject unknown timezone values. */
    function normalize_timezone(?string $timezone, string $default = 'UTC'): string
    {
        $legacy = [
            'eastern'  => 'America/New_York',
            'central'  => 'America/Chicago',
            'central2' => 'America/Chicago',
            'mountain' => 'America/Denver',
            'pacific'  => 'America/Los_Angeles',
        ];

        $timezone = trim((string) $timezone);
        $timezone = $legacy[strtolower($timezone)] ?? $timezone;
        return is_valid_timezone($timezone) ? $timezone : $default;
    }
}

if (!function_exists('random_secret')) {
    function random_secret(int $length = 12): string
    {
        // Avoid ambiguous chars (0/O, 1/l/I) so secrets are easy to retype.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}

if (!function_exists('random_pin')) {
    function random_pin(int $length = 6): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= (string) random_int(0, 9);
        }
        return $out;
    }
}

if (!function_exists('mask_secret')) {
    /** Show only the last few chars of a stored secret in lists. */
    function mask_secret(?string $secret): string
    {
        if (!$secret) {
            return '—';
        }
        $len = strlen($secret);
        return $len <= 4 ? str_repeat('*', $len) : str_repeat('*', $len - 4) . substr($secret, -4);
    }
}

if (!function_exists('format_bytes')) {
    /** Human-readable byte size: 512 B, 1.4 MB, 12.3 GB. */
    function format_bytes(int|float $bytes, int $decimals = 1): string
    {
        $bytes = (float) $bytes;
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'kB', 'MB', 'GB', 'TB', 'PB'];
        $index = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $index);

        if ($index === 0) {
            return (string) (int) round($value) . ' B';
        }
        return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.') . ' ' . $units[$index];
    }
}

if (!function_exists('format_rate')) {
    /** Throughput label for the server-resource card: "1.2 kB/s". */
    function format_rate(int|float $bytesPerSecond): string
    {
        return format_bytes($bytesPerSecond) . '/s';
    }
}

if (!function_exists('usage_level')) {
    /**
     * Threshold class for a usage percentage so the meter colour follows
     * the same green / amber / red language as the rest of the panel.
     */
    function usage_level(float $percent): string
    {
        if ($percent >= 85) {
            return 'hot';
        }
        if ($percent >= 70) {
            return 'warn';
        }
        return 'ok';
    }
}

if (!function_exists('server_resource_chart')) {
    /**
     * SVG line chart of the last 24 hours of CPU / memory / disk percentage.
     *
     * @param array<int, array{t:int,cpu?:float|null,mem?:float|null,disk?:float|null}> $series
     * @return string '' when there is not enough data to draw anything.
     */
    function server_resource_chart(array $series, int $width = 900, int $height = 250): string
    {
        $series = array_values(array_filter($series, 'is_array'));
        if (count($series) < 2) {
            return '';
        }

        $padL = 48;
        $padR = 14;
        $padT = 16;
        $padB = 30;
        $x0 = $padL;
        $x1 = $width - $padR;
        $y0 = $padT;
        $y1 = $height - $padB;

        $t0 = (int) ($series[0]['t'] ?? 0);
        $t1 = (int) ($series[count($series) - 1]['t'] ?? 0);
        if ($t1 <= $t0) {
            $t1 = $t0 + 1;
        }
        $span = $t1 - $t0;

        $px = static fn (int $t): float => $x0 + ($t - $t0) / $span * ($x1 - $x0);
        $py = static fn (float $p): float => $y1 - (max(0.0, min(100.0, $p)) / 100) * ($y1 - $y0);

        $parts = [];

        // Horizontal grid + percentage axis.
        foreach ([0, 25, 50, 75, 100] as $pct) {
            $y = round($py((float) $pct), 1);
            $parts[] = '<line x1="' . $x0 . '" y1="' . $y . '" x2="' . $x1 . '" y2="' . $y . '" stroke="currentColor" stroke-opacity=".16" stroke-width="1"/>';
            $parts[] = '<text x="' . ($x0 - 9) . '" y="' . round($y + 3.5, 1) . '" text-anchor="end" class="chart-axis">' . $pct . '%</text>';
        }

        // Time axis: five ticks from "24h ago" to "now".
        foreach ([0.0, 0.25, 0.5, 0.75, 1.0] as $fraction) {
            $x   = round($px($t0 + (int) round($fraction * $span)), 1);
            $ago = $span * (1 - $fraction);
            $label = $fraction >= 1.0
                ? 'now'
                : ($ago >= 3600 ? '-' . (int) round($ago / 3600) . 'h' : '-' . (int) round($ago / 60) . 'm');
            $anchor = $fraction <= 0.0 ? 'start' : ($fraction >= 1.0 ? 'end' : 'middle');
            $parts[] = '<text x="' . $x . '" y="' . ($y1 + 19) . '" text-anchor="' . $anchor . '" class="chart-axis">' . $label . '</text>';
        }

        $lines = [
            ['key' => 'cpu',  'color' => '#3b66f5'],
            ['key' => 'mem',  'color' => '#7c5cff'],
            ['key' => 'disk', 'color' => '#d97706'],
        ];

        $plotted = 0;
        foreach ($lines as $line) {
            $path  = '';
            $open  = false;
            $point = 0;

            foreach ($series as $sample) {
                $value = $sample[$line['key']] ?? null;
                if ($value === null || !is_numeric($value)) {
                    $open = false;
                    continue;
                }
                $path .= ($open ? 'L' : 'M')
                    . round($px((int) $sample['t']), 1) . ' '
                    . round($py((float) $value), 1) . ' ';
                $open = true;
                $point++;
            }

            $path = trim($path);
            if ($point < 2 || $path === '') {
                continue;
            }
            $parts[] = '<path d="' . $path . '" fill="none" stroke="' . $line['color']
                . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
            $plotted++;
        }

        if ($plotted === 0) {
            return ''; // nothing was actually plotted
        }

        return '<svg class="chart" viewBox="0 0 ' . $width . ' ' . $height
            . '" role="img" aria-label="CPU, memory and disk usage over the last 24 hours">'
            . implode('', $parts) . '</svg>';
    }
}

if (!function_exists('human_mac')) {
    function human_mac(string $mac): string
    {
        $compact = strtolower(str_replace([':', '-', '.'], '', $mac));
        if (strlen($compact) !== 12) {
            return $mac;
        }
        return implode(':', str_split($compact, 2));
    }
}

if (!function_exists('mac_compact')) {
    function mac_compact(string $mac): string
    {
        return strtolower(str_replace([':', '-', '.'], '', $mac));
    }
}

if (!function_exists('is_superadmin')) {
    function is_superadmin(): bool
    {
        return auth()->isSuperAdmin();
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return auth()->isAdmin();
    }
}

if (!function_exists('can')) {
    function can(string $ability): bool
    {
        return auth()->can($ability);
    }
}

if (!function_exists('flash')) {
    function flash(string $type, string $message): void
    {
        Flash::add($type, $message);
    }
}

// Alias for the Str helper that's referenced by provisioning code.
if (!class_exists('App\Core\Str') && class_exists('App\Support\Str')) {
    class_alias('App\Support\Str', 'App\Core\Str');
}
