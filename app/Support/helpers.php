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
