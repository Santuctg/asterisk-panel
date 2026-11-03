<?php
/**
 * Panel users. Expects: $rows, $tenants, $roleOptions
 */
require dirname(__DIR__) . '/partials/fields.php';

$roleOpts = (array) $roleOptions;

$tenantOpts = [];
foreach ((array) $tenants as $t) { $tenantOpts[$t['id']] = $t['name']; }
?>

<div class="page-head">
    <div>
        <h1>Users</h1>
        <div class="subtitle">Accounts for <?= e(reset($tenantOpts) ?: 'the active tenant') ?></div>
    </div>
    <div class="page-actions">
        <a class="btn" href="/roles">Manage roles</a>
        <button class="btn btn-primary" data-modal-open="userModal">
            <?= $this->fragment('icons/plus') ?> New user
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon'   => 'user',
                'title'  => 'No users yet',
                'message'=> 'Create accounts so colleagues can manage the phone system.',
                'action_html' => '<button class="btn btn-primary" data-modal-open="userModal">Create user</button>',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Tenant</th>
                            <th>Role</th>
                            <th>Last sign-in</th>
                            <th>Status</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $u): ?>
                            <?php $editUser = array_intersect_key($u, array_flip(['id','username','email','tenant_id','role','active'])); ?>
                            <tr>
                                <td>
                                    <strong><?= e($u['username']) ?></strong>
                                    <?php if ((int) $u['id'] === auth()->id()): ?>
                                        <span class="badge blue">You</span>
                                    <?php endif; ?>
                                </td>
                                <td class="muted"><?= e($u['email'] ?: '—') ?></td>
                                <td><?= e($u['tenant_name'] ?: '—') ?></td>
                                <td>
                                    <span class="badge <?= $u['role'] === 'superadmin' ? 'violet' : ($u['role'] === 'admin' ? 'blue' : 'gray') ?>">
                                        <?= e($u['role_name'] ?: ($roleOpts[$u['role']] ?? ucfirst((string) $u['role']))) ?>
                                    </span>
                                </td>
                                <td class="muted text-xs nowrap">
                                    <?= $u['last_login'] ? e(format_date($u['last_login'])) : 'Never' ?>
                                </td>
                                <td>
                                    <?= $u['active']
                                        ? '<span class="badge green"><span class="dot"></span>Active</span>'
                                        : '<span class="badge gray"><span class="dot"></span>Disabled</span>' ?>
                                </td>
                                <td class="actions">
                                    <button class="btn btn-sm" data-modal-open="userModal"
                                            data-edit='<?= e(json_encode($editUser, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                                    <?php if ((int) $u['id'] !== auth()->id()): ?>
                                        <form method="post" action="/users/<?= (int) $u['id'] ?>">
                                            <?= csrf() ?><input type="hidden" name="_method" value="DELETE">
                                            <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                    data-confirm-btn="Delete user <?= e($u['username']) ?>?">
                                                <?= $this->fragment('icons/trash') ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="userModal" hidden>
    <div class="modal">
        <form method="post" action="/users" id="userForm">
            <?= csrf() ?>
            <div class="modal-head">
                <h2 id="userTitle">New user</h2>
                <button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <?php echo field_text('username', 'Username', '', [
                        'required' => true, 'autocomplete' => 'username',
                        'hint' => 'Letters, numbers, dashes and underscores.',
                    ]); ?>
                    <?php echo field_text('email', 'Email', '', ['type' => 'email']); ?>
                    <?php echo field_select('role', 'Role', $roleOpts, 'user', [
                        'required' => true,
                        'hint' => 'Tenant users cannot sign in through another tenant domain. Super admins use the main panel domain.',
                    ]); ?>
                    <?php echo field_text('password', 'Password', '', [
                        'type' => 'password', 'span' => true, 'autocomplete' => 'new-password',
                        'hint' => 'Minimum 8 characters. On edit, leave blank to keep the current password.',
                    ]); ?>
                    <div class="span-2">
                        <?php echo field_check('enabled', 'Account active', true); ?>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="userSubmit">Create user</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal  = document.getElementById('userModal');
    var form   = document.getElementById('userForm');
    var title  = document.getElementById('userTitle');
    var submit = document.getElementById('userSubmit');

    document.querySelectorAll('[data-modal-open="userModal"][data-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
        var d = JSON.parse(btn.getAttribute('data-edit'));
        form.action = '/users/' + d.id;
        title.textContent = 'Edit user';
        submit.textContent = 'Save changes';
        form.elements['username'].value = d.username || '';
        form.elements['email'].value = d.email || '';
        if (form.elements['role']) form.elements['role'].value = d.role || 'user';
        if (form.elements['tenant_id']) form.elements['tenant_id'].value = d.tenant_id || '';
        form.elements['password'].value = '';
        form.elements['enabled'].checked = Number(d.active) === 1;
        });
    });

    document.querySelectorAll('[data-modal-open="userModal"]').forEach(function (opener) {
        if (opener.hasAttribute('data-edit')) return;
        opener.addEventListener('click', function () {
            form.reset(); form.action = '/users';
            title.textContent = 'New user'; submit.textContent = 'Create user';
        });
    });
})();
</script>
