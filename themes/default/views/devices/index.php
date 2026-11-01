<?php
/** Devices list. Expects: $rows */
require dirname(__DIR__) . '/partials/fields.php';
?>

<div class="page-head">
    <div>
        <h1>Devices</h1>
        <div class="subtitle">IP phones, MAC addresses and auto-provisioning</div>
    </div>
    <div class="page-actions">
        <a href="/devices/create" class="btn btn-primary">
            <?= $this->fragment('icons/plus') ?> Add device
        </a>
    </div>
</div>

<div class="flash flash-info">
    <span class="flash-icon"><?= $this->fragment('icons/info') ?></span>
    <span class="flash-msg">
        Point each phone's provisioning URL at the address shown below. The panel serves a
        vendor-specific config built from the linked extension's credentials.
    </span>
</div>

<div class="card">
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon'   => 'device',
                'title'  => 'No devices registered',
                'message'=> 'Add a phone by its MAC address to enable auto-provisioning.',
                'action_html' => '<a class="btn btn-primary" href="/devices/create">Add device</a>',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>MAC</th>
                            <th>Vendor / Model</th>
                            <th>Extension</th>
                            <th>Provisioning URL</th>
                            <th>Status</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td>
                                    <span class="mono"><?= e(human_mac($r['mac'])) ?></span>
                                    <?php if (!empty($r['description'])): ?>
                                        <div class="text-xs muted"><?= e($r['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge blue"><?= e(ucfirst((string) $r['vendor'])) ?></span>
                                    <?php if (!empty($r['model'])): ?>
                                        <div class="text-xs muted"><?= e($r['model']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($r['ext'])): ?>
                                        <strong class="mono"><?= e($r['ext']) ?></strong>
                                        <div class="text-xs muted"><?= e($r['ext_name']) ?></div>
                                    <?php else: ?>
                                        <span class="muted">Unassigned</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="copy-field">
                                        <input type="text" readonly value="<?= e($r['provision_url']) ?>"
                                               style="min-width:260px" id="prov-<?= (int) $r['id'] ?>">
                                        <button class="btn btn-sm" type="button"
                                                data-copy-target="prov-<?= (int) $r['id'] ?>">
                                            <?= $this->fragment('icons/copy') ?>
                                        </button>
                                    </div>
                                </td>
                                <td>
                                    <?= $r['enabled']
                                        ? '<span class="badge green"><span class="dot"></span>Active</span>'
                                        : '<span class="badge gray"><span class="dot"></span>Disabled</span>' ?>
                                </td>
                                <td class="actions">
                                    <a href="/devices/<?= (int) $r['id'] ?>/edit" class="btn btn-sm">Edit</a>
                                    <form method="post" action="/devices/<?= (int) $r['id'] ?>/resync">
                                        <?= csrf() ?>
                                        <button class="btn btn-sm" title="Clear cached config (forces re-fetch)">
                                            <?= $this->fragment('icons/refresh') ?> Resync
                                        </button>
                                    </form>
                                    <form method="post" action="/devices/<?= (int) $r['id'] ?>">
                                        <?= csrf() ?>
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                data-confirm-btn="Remove device <?= e(human_mac($r['mac'])) ?>?">
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
