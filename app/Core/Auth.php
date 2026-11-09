<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\TenantHost;
use App\Services\TenantSecurity;

/**
 * Authentication + authorization.
 *
 * Roles (least to greatest privilege):
 *   user        – limited access, e.g. call center supervisor
 *   admin       – full control of their own tenant
 *   superadmin  – control of every tenant + system settings
 */
final class Auth
{
    private ?array $user = null;
    private bool $loaded = false;

    public function attempt(string $username, string $password): bool
    {
        $db = App::getInstance()->db();

        $rows = $db->all(
            'SELECT * FROM users WHERE (username = :u OR email = :u) AND active = 1',
            ['u' => $username]
        );
        $matches = [];
        foreach ($rows as $candidate) {
            $tenantId = (int) ($candidate['tenant_id'] ?? 0);
            $blocked = $tenantId > 0 && (new TenantSecurity())->isBlocked(
                $tenantId,
                (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')
            );
            if (!$blocked && TenantHost::allows($candidate) && password_verify($password, $candidate['password'])) {
                $matches[] = $candidate;
            }
        }
        // The main hostname can serve multiple domain-less tenants. If the
        // same credentials match more than one account, fail closed instead
        // of selecting an arbitrary tenant.
        if (count($matches) !== 1) {
            return false;
        }
        $row = $matches[0];

        // Re-hash if the algorithm strength changed.
        if (password_needs_rehash($row['password'], PASSWORD_DEFAULT)) {
            $db->update('users', (int) $row['id'], [
                'password' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        }

        $db->update('users', (int) $row['id'], [
            'last_login'      => date('Y-m-d H:i:s'),
            'last_login_ip'   => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        Session::regenerate();
        Session::put('user_id', (int) $row['id']);
        $this->user  = $row;
        $this->loaded = true;

        return true;
    }

    public function login(int $userId): void
    {
        Session::regenerate();
        Session::put('user_id', $userId);
        $this->loaded = false;
        $this->user   = null;
    }

    public function logout(): void
    {
        Session::destroy();
        $this->user   = null;
        $this->loaded = true;
    }

    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->user;
        }

        $this->loaded = true;
        $id           = Session::get('user_id');

        if ($id) {
            $this->user = App::getInstance()->db()->one('SELECT * FROM users WHERE id = ? AND active = 1', [(int) $id]);
            if ($this->user && !TenantHost::allows($this->user)) {
                Session::forget('user_id');
                $this->user = null;
            }
        }

        return $this->user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function id(): ?int
    {
        $u = $this->user();
        return $u ? (int) $u['id'] : null;
    }

    public function role(): string
    {
        return $this->user()['role'] ?? 'guest';
    }

    public function isSuperAdmin(): bool
    {
        return $this->role() === 'superadmin';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role(), ['admin', 'superadmin'], true);
    }

    public function can(string $ability): bool
    {
        $role = $this->role();

        if ($role === 'superadmin') {
            return true;
        }

        // Regenerating every tenant's config and reloading Asterisk is a
        // global control-plane operation, never a delegable tenant ability.
        if ($ability === 'system.apply') {
            return false;
        }

        $user = $this->user();
        if (!$user || empty($user['tenant_id'])) { return false; }
        $json = App::getInstance()->db()->scalar(
            'SELECT permissions FROM roles WHERE tenant_id = ? AND slug = ?',
            [(int) $user['tenant_id'], $role]
        );
        $permissions = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($permissions) && in_array($role, ['admin','user'], true)) {
            $permissions = self::defaultPermissions($role);
        }
        if (!is_array($permissions)) { return false; }
        if (in_array($ability, $permissions, true)) { return true; }
        // Manage permission always includes the corresponding read-only view.
        if (str_ends_with($ability, '.view')) {
            return in_array(substr($ability, 0, -5).'.manage', $permissions, true);
        }
        return false;
    }

    /** Permissions that may be granted by a tenant-scoped custom role. */
    public static function assignableAbilities(): array
    {
        return [
            'dashboard.view' => 'View dashboard',
            'webphone.use' => 'Use Web Phone',
            'extension.view' => 'Extensions — view', 'extension.manage' => 'Extensions — create/edit/delete',
            'contact.view' => 'Contacts — view', 'contact.manage' => 'Contacts — create/edit/delete/import',
            'voicemail.view' => 'Voicemail — view', 'voicemail.manage' => 'Voicemail — create/edit/delete',
            'device.view' => 'Devices — view', 'device.manage' => 'Devices — create/edit/delete',
            'ringgroup.view' => 'Ring groups — view', 'ringgroup.manage' => 'Ring groups — create/edit/delete',
            'parking.view' => 'Call parking — view', 'parking.manage' => 'Call parking — create/edit/delete',
            'ivr.view' => 'IVR menus — view', 'ivr.manage' => 'IVR menus — create/edit/delete',
            'route.view' => 'Call routing — view', 'route.manage' => 'Call routing — create/edit/delete',
            'moh.view' => 'Audio — view', 'moh.manage' => 'Audio — create/edit/delete',
            'callcenter.view' => 'Call center — view', 'callcenter.manage' => 'Call center — create/edit/delete',
            'conference.view' => 'Conferences — view', 'conference.manage' => 'Conferences — create/edit/delete',
            'cdr.view' => 'Call history — view',
            'cdr.delete' => 'Delete call history',
            'call.listen' => 'Active calls — view/listen',
            'call.hangup' => 'Active calls — end calls',
            'user.view' => 'Users — view', 'user.manage' => 'Users — create/edit/delete',
            'role.view' => 'Roles — view', 'role.manage' => 'Roles — create/edit/delete',
            'tenant_security.view' => 'Tenant login security — view',
            'tenant_security.manage' => 'Tenant login security — block/unblock IPs',
            'system_status.view' => 'System status — view',
            'system_settings.view' => 'Tenant settings — view', 'system_settings.manage' => 'Tenant settings — edit',
            'audit.view' => 'Audit log — view',
        ];
    }

    public static function defaultPermissions(string $role): array
    {
        if ($role === 'admin') { return array_keys(self::assignableAbilities()); }
        if ($role === 'user') { return ['dashboard.view','webphone.use','cdr.view']; }
        return [];
    }

    /** First panel destination the current user is allowed to open. */
    public function landingPath(): string
    {
        $destinations = [
            'dashboard.view' => '/dashboard',
            'webphone.use' => '/web-phone',
            'call.listen' => '/active-calls',
            'extension.view' => '/extensions',
            'contact.view' => '/contacts',
            'voicemail.view' => '/voicemail',
            'device.view' => '/devices',
            'ringgroup.view' => '/ring-groups',
            'parking.view' => '/parking',
            'ivr.view' => '/ivr',
            'route.view' => '/routes',
            'moh.view' => '/moh',
            'callcenter.view' => '/call-center',
            'conference.view' => '/conferences',
            'cdr.view' => '/cdr',
            'user.view' => '/users',
            'role.view' => '/roles',
            'tenant_security.view' => '/tenant-security',
            'system_status.view' => '/system/status',
            'system_settings.view' => '/system/settings',
            'audit.view' => '/system/audit',
        ];

        foreach ($destinations as $ability => $path) {
            if ($this->can($ability)) {
                return $path;
            }
        }

        return '/profile';
    }

    /**
     * Middleware factory: require login (and optionally an ability).
     * Tenant scoping is applied by the controllers via currentTenantId().
     */
    public static function require(string $ability = ''): callable
    {
        return static function (Request $request, callable $next) use ($ability): mixed {
            $auth = App::getInstance()->auth();

            if (!$auth->check()) {
                if ($request->wantsJson()) {
                    return Response::json(['error' => 'Unauthenticated'], 401);
                }
                return Response::redirect('/login');
            }

            if ($ability !== '' && !$auth->can($ability)) {
                Flash::error('You do not have permission to perform that action.');
                return Response::redirect($auth->landingPath());
            }

            return $next($request);
        };
    }
}
