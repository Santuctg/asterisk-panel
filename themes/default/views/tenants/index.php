<?php
/**
 * Tenants management (superadmin). Expects: $rows (with 'counts')
 */
require dirname(__DIR__) . '/partials/fields.php';

$timezoneOpts = timezone_options();
?>

<div class="page-head">
    <div>
        <h1>Tenants</h1>
        <div class="subtitle">Businesses / domains sharing this PBX. Each gets its own Asterisk dialplan context.</div>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-modal-open="tenantModal">
            <?= $this->fragment('icons/plus') ?> New tenant
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon'   => 'building',
                'title'  => 'No tenants',
                'message'=> 'Create a tenant to scope extensions, routes and users to a business.',
                'action_html' => '<button class="btn btn-primary" data-modal-open="tenantModal">Create tenant</button>',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Context</th>
                            <th>Domain</th>
                            <th class="num">Extensions</th>
                            <th class="num">Users</th>
                            <th>Status</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $t): ?>
                            <tr>
                                <td>
                                    <strong><?= e($t['name']) ?></strong>
                                    <?php if (!empty($t['contact_email'])): ?>
                                        <div class="text-xs muted"><?= e($t['contact_email']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><code class="mono"><?= e($t['context']) ?></code></td>
                                <td class="muted"><?= e($t['domain'] ?: '—') ?></td>
                                <td class="num"><?= (int) $t['counts']['extensions'] ?></td>
                                <td class="num"><?= (int) $t['counts']['users'] ?></td>
                                <td>
                                    <?= $t['enabled']
                                        ? '<span class="badge green"><span class="dot"></span>Active</span>'
                                        : '<span class="badge gray"><span class="dot"></span>Disabled</span>' ?>
                                </td>
                                <td class="actions">
                                    <?php if (!empty($t['domain'])): ?><a class="btn btn-sm" href="/tenants/<?= (int)$t['id'] ?>/ssl">SSL</a><?php endif; ?>
                                    <button class="btn btn-sm" data-modal-open="tenantModal"
                                            data-edit='<?= e(json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                                    <form method="post" action="/tenants/<?= (int) $t['id'] ?>">
                                        <?= csrf() ?><input type="hidden" name="_method" value="DELETE">
                                        <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                data-confirm-btn="Delete tenant <?= e($t['name']) ?>? This removes all of its extensions, routes, users and devices.">
                                            <?= $this->fragment('icons/trash') ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal-overlay" id="tenantModal" hidden>
    <div class="modal">
        <form method="post" action="/tenants" id="tenantForm">
            <?= csrf() ?>
            <div class="modal-head">
                <h2 id="tenantTitle">New tenant</h2>
                <button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <?php echo field_text('name', 'Business name', '', [
                        'required' => true, 'span' => true,
                        'hint' => 'The dialplan context is generated from this and cannot be changed later.',
                    ]); ?>
                    <?php echo field_text('domain', 'Domain / SIP realm', '', [
                        'placeholder' => 'e.g. acme.example.com',
                    ]); ?>
                    <?php echo field_select('timezone', 'Timezone', $timezoneOpts, 'America/New_York'); ?>
                    <?php echo field_text('contact_email', 'Contact email', '', ['type' => 'email']); ?>
                    <?php echo field_text('contact_phone', 'Contact phone', ''); ?>
                    <?php echo field_textarea('notes', 'Notes', '', ['rows' => 2, 'span' => true]); ?>
                    <div class="span-2"><?php echo field_check('enabled', 'Tenant active', true); ?></div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="tenantSubmit">Create tenant</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal  = document.getElementById('tenantModal');
    var form   = document.getElementById('tenantForm');
    var title  = document.getElementById('tenantTitle');
    var submit = document.getElementById('tenantSubmit');

    document.querySelectorAll('[data-modal-open="tenantModal"][data-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
        var d = JSON.parse(btn.getAttribute('data-edit'));
        form.action = '/tenants/' + d.id;
        title.textContent = 'Edit tenant';
        submit.textContent = 'Save changes';
        form.elements['name'].value = d.name || '';
        form.elements['domain'].value = d.domain || '';
        form.elements['timezone'].value = d.timezone || 'America/New_York';
        form.elements['contact_email'].value = d.contact_email || '';
        form.elements['contact_phone'].value = d.contact_phone || '';
        form.elements['notes'].value = d.notes || '';
        form.elements['enabled'].checked = Number(d.enabled) === 1;
        });
    });

    document.querySelectorAll('[data-modal-open="tenantModal"]').forEach(function (opener) {
        if (opener.hasAttribute('data-edit')) return;
        opener.addEventListener('click', function () {
            form.reset(); form.action = '/tenants';
            title.textContent = 'New tenant'; submit.textContent = 'Create tenant';
        });
    });
})();
</script>
