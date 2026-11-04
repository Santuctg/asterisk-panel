<?php
/**
 * Authenticated app shell.
 * Expects: $content (rendered view), $auth, $flashes
 */
$currentPage = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$user = $auth->user();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(\App\Core\Csrf::token()) ?>">
    <title><?= e(config('app.name', 'Asterisk Panel')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📞</text></svg>">
</head>
<body>
<div class="app-shell">

    <?php
    // Navigation structure: [label, href|children, ability|null, icon]
    $nav = [
        ['label' => 'Dashboard',    'href' => '/dashboard',      'icon' => 'grid',    'ability' => null],
        ['label' => 'Web Phone',    'href' => '/web-phone',       'icon' => 'phone',   'ability' => 'webphone.use'],
        ['label' => 'Extensions',   'href' => '/extensions',     'icon' => 'phone',   'ability' => 'extension.view'],
        ['label' => 'Voicemail',    'href' => '/voicemail',      'icon' => 'mail',    'ability' => 'voicemail.view'],
        ['label' => 'Devices',      'href' => '/devices',        'icon' => 'device',  'ability' => 'device.view'],
        ['label' => 'Ring Groups',  'href' => '/ring-groups',    'icon' => 'users',   'ability' => 'ringgroup.view'],
        ['label' => 'Call Parking', 'href' => '/parking',        'icon' => 'park',    'ability' => 'parking.view'],
        ['label' => 'IVR Menus',    'href' => '/ivr',            'icon' => 'menu',    'ability' => 'ivr.view'],
        ['label' => 'Call Routing', 'href' => '/routes',         'icon' => 'route',   'ability' => 'route.view'],
        ['label' => 'Music on Hold','href' => '/moh',            'icon' => 'music',   'ability' => 'moh.view'],
        ['label' => 'Call Center',  'href' => '/call-center',    'icon' => 'headset', 'ability' => 'callcenter.view'],
        ['label' => 'Conferences',  'href' => '/conferences',    'icon' => 'conf',    'ability' => 'conference.view'],
        ['label' => 'Call History', 'href' => '/cdr',            'icon' => 'clock',   'ability' => 'cdr.view'],
        ['label' => 'Tenants',      'href' => '/tenants',        'icon' => 'building','ability' => 'tenant.view'],
        ['label' => 'Users',        'href' => '/users',          'icon' => 'user',    'ability' => 'user.view'],
        ['label' => 'Roles',        'href' => '/roles',          'icon' => 'users',   'ability' => 'role.view'],
        ['label' => 'Tenant Security','href' => '/tenant-security','icon' => 'shield', 'ability' => 'tenant_security.view'],
        ['label' => 'Global Admins','href' => '/global-admins',  'icon' => 'user',    'ability' => 'tenant.manage'],
        ['label' => 'Fail2ban',      'href' => '/fail2ban',       'icon' => 'shield',  'ability' => 'security.fail2ban'],
        ['label' => 'System',       'href' => '/system/status',  'icon' => 'cog',     'ability' => null,
            'children' => [
                ['label' => 'Status',      'href' => '/system/status'],
                ['label' => 'Settings',    'href' => '/system/settings'],
                ['label' => 'SSL / HTTPS', 'href' => '/system/ssl'],
                ['label' => 'Audit Log',   'href' => '/system/audit'],
            ],
        ],
        ['label' => 'System Update', 'href' => '/system/update', 'icon' => 'refresh', 'ability' => 'system.update'],
    ];

    $canSee = static fn (array $item): bool => empty($item['ability']) || \App\Core\App::getInstance()->auth()->can($item['ability']);
    ?>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-mark">P</div>
            <div class="brand-text">
                <strong><?= e(config('app.name', 'Asterisk Panel')) ?></strong>
                <span>v<?= e(config('app.version', '1.0')) ?></span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <?php foreach ($nav as $item): ?>
                <?php if (!$canSee($item)) continue; ?>
                <?php
                $hasChildren = !empty($item['children']);
                $active = str_starts_with($currentPage, $item['href']);
                if ($hasChildren) {
                    foreach ($item['children'] as $child) {
                        if ($currentPage === $child['href']) {
                            $active = true;
                            break;
                        }
                    }
                }
                ?>
                <div class="nav-group">
                    <a href="<?= e($item['href']) ?>"
                       class="nav-link<?= $active && !$hasChildren ? ' is-active' : '' ?>">
                        <span class="nav-icon"><?= $this->fragment('icons/' . $item['icon']) ?></span>
                        <span class="nav-label"><?= e($item['label']) ?></span>
                    </a>

                    <?php if ($hasChildren && $active): ?>
                        <div class="nav-children">
                            <?php foreach ($item['children'] as $child): ?>
                                <a href="<?= e($child['href']) ?>"
                                   class="nav-child<?= $currentPage === $child['href'] ? ' is-active' : '' ?>">
                                    <?= e($child['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-meta">Asterisk <?= e($astVer ?? '') ?></div>
        </div>
    </aside>

    <!-- Main -->
    <div class="main">
        <header class="topbar">
            <button class="icon-btn" id="sidebarToggle" aria-label="Toggle navigation">
                <span class="nav-icon"><?= $this->fragment('icons/menu') ?></span>
            </button>

            <div class="topbar-search">
                <input type="search" id="globalSearch" placeholder="Search extensions, devices, queues…"
                       autocomplete="off" aria-label="Search">
            </div>

            <div class="topbar-actions">
                <?php
                // Tenant switcher (multi-tenant installs).
                $tenants = [];
                if (\App\Core\App::getInstance()->auth()->isSuperAdmin()) {
                    $hostTenant = \App\Services\TenantHost::tenantIdForRequest();
                    $tenants = $hostTenant
                        ? \App\Core\App::getInstance()->db()->all('SELECT id, name FROM tenants WHERE id = ? AND enabled = 1', [$hostTenant])
                        : \App\Core\App::getInstance()->db()->all('SELECT id, name FROM tenants WHERE enabled = 1 ORDER BY name');
                } else {
                    $tid = $user['tenant_id'] ?? null;
                    if ($tid) {
                        $tenants = \App\Core\App::getInstance()->db()->all('SELECT id, name FROM tenants WHERE id = ?', [$tid]);
                    }
                }
                $activeTenant = function_exists('tenant_id') ? tenant_id() : ($_SESSION['active_tenant'] ?? null);
                ?>
                <?php if (count($tenants) > 1): ?>
                    <form method="post" action="/dashboard/switch-tenant" class="tenant-switch">
                        <?= \App\Core\Csrf::field() ?>
                        <select name="tenant_id" onchange="this.form.submit()" aria-label="Switch tenant">
                            <?php foreach ($tenants as $t): ?>
                                <option value="<?= (int) $t['id'] ?>"
                                    <?= (int) $activeTenant === (int) $t['id'] ? 'selected' : '' ?>>
                                    <?= e($t['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php elseif (count($tenants) === 1): ?>
                    <span class="tenant-badge"><?= e($tenants[0]['name']) ?></span>
                <?php endif; ?>

                <a href="/system/status" class="icon-btn" title="System status">
                    <span class="nav-icon"><?= $this->fragment('icons/pulse') ?></span>
                </a>

                <div class="user-menu">
                    <button class="user-btn" id="userMenuBtn" aria-haspopup="true" aria-expanded="false">
                        <span class="avatar"><?= e(strtoupper(substr((string) ($user['username'] ?? '?'), 0, 1))) ?></span>
                        <span class="user-meta">
                            <strong><?= e($user['username'] ?? '') ?></strong>
                            <small><?= e(ucfirst((string) ($user['role'] ?? ''))) ?></small>
                        </span>
                        <span class="nav-icon chev"><?= $this->fragment('icons/chevron') ?></span>
                    </button>
                    <div class="dropdown" id="userMenu" hidden>
                        <a href="/profile" class="dropdown-item"><?= $this->fragment('icons/user') ?> My Profile</a>
                        <a href="/system/settings" class="dropdown-item"><?= $this->fragment('icons/cog') ?> Settings</a>
                        <div class="dropdown-divider"></div>
                        <form method="post" action="/logout">
                            <?= \App\Core\Csrf::field() ?>
                            <button type="submit" class="dropdown-item danger">
                                <?= $this->fragment('icons/logout') ?> Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="content">
            <?php if (!empty($flashes)): ?>
                <div class="flash-stack">
                    <?php foreach ($flashes as $f): ?>
                        <div class="flash flash-<?= e($f['type']) ?>" data-flash>
                            <span class="flash-icon"><?= $this->fragment('icons/' . ($f['type'] === 'success' ? 'check' : ($f['type'] === 'error' ? 'alert' : 'info'))) ?></span>
                            <span class="flash-msg"><?= e($f['message']) ?></span>
                            <button class="flash-close" aria-label="Dismiss"><?= $this->fragment('icons/x') ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</div>

<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
</body>
</html>
