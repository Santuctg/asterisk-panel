<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\TenantHost;

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
            if (TenantHost::allows($candidate) && password_verify($password, $candidate['password'])) {
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

        $matrix = [
            'admin' => [
                'dashboard.view', 'extension.manage', 'voicemail.manage', 'device.manage',
                'ringgroup.manage', 'parking.manage', 'ivr.manage', 'route.manage',
                'moh.manage', 'callcenter.manage', 'conference.manage', 'cdr.view',
                'cdr.delete', 'user.manage', 'role.manage', 'call.listen',
            ],
            'user' => [
                'dashboard.view', 'cdr.view',
            ],
        ];

        if (isset($matrix[$role])) {
            return in_array($ability, $matrix[$role], true);
        }

        $user = $this->user();
        if (!$user || empty($user['tenant_id'])) { return false; }
        $json = App::getInstance()->db()->scalar(
            'SELECT permissions FROM roles WHERE tenant_id = ? AND slug = ?',
            [(int) $user['tenant_id'], $role]
        );
        if (!is_string($json)) { return false; }
        $permissions = json_decode($json, true);
        return is_array($permissions) && in_array($ability, $permissions, true);
    }

    /** Permissions that may be granted by a tenant-scoped custom role. */
    public static function assignableAbilities(): array
    {
        return [
            'dashboard.view' => 'View dashboard',
            'extension.manage' => 'Manage extensions',
            'voicemail.manage' => 'Manage voicemail',
            'device.manage' => 'Manage devices',
            'ringgroup.manage' => 'Manage ring groups',
            'parking.manage' => 'Manage call parking',
            'ivr.manage' => 'Manage IVR menus',
            'route.manage' => 'Manage call routing',
            'moh.manage' => 'Manage music on hold',
            'callcenter.manage' => 'Manage call center',
            'conference.manage' => 'Manage conferences',
            'cdr.view' => 'View call history',
            'cdr.delete' => 'Delete call history',
            'call.listen' => 'Use live listen',
        ];
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
                return Response::redirect('/dashboard');
            }

            return $next($request);
        };
    }
}
