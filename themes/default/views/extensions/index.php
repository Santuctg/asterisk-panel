<?php
/** Extensions list. Expects: $rows, $q, $page, $pages, $total */
require dirname(__DIR__) . '/partials/fields.php';
?>

<div class="page-head">
    <div>
        <h1>Extensions</h1>
        <div class="subtitle"><?= (int) $total ?> extension<?= $total === 1 ? '' : 's' ?> · SIP endpoints your phones register as</div>
    </div>
    <div class="page-actions">
        <a href="/extensions/create" class="btn btn-primary">
            <?= $this->fragment('icons/plus') ?> New extension
        </a>
    </div>
</div>

<div class="card">
    <div class="toolbar">
        <form method="get" action="/extensions" class="flex gap-2 items-center w-full">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search number, name or caller ID…"
                   data-filter-input="#extTable" style="max-width:320px">
            <span class="spacer"></span>
            <span class="muted text-sm"><?= (int) $total ?> total</span>
        </form>
    </div>

    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon'   => 'phone',
                'title'  => $q ? 'No extensions match your search' : 'No extensions yet',
                'message'=> $q ? 'Try a different search term.' : 'Create your first extension to start connecting phones.',
                'action_html' => $q ? '' : '<a class="btn btn-primary" href="/extensions/create">Create extension</a>',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table id="extTable">
                    <thead>
                        <tr>
                            <th>Ext</th>
                            <th>Name</th>
                            <th>Caller ID</th>
                            <th>SIP password</th>
                            <th>Voicemail</th>
                            <th>Device</th>
                            <th>Registration</th>
                            <th>Config</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td>
                                    <strong class="mono"><?= e($r['extension']) ?></strong>
                                </td>
                                <td>
                                    <?= e($r['name']) ?>
                                    <?php if (!empty($r['description'])): ?>
                                        <div class="text-xs muted"><?= e($r['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($r['cid_name'])): ?>
                                        <?= e($r['cid_name']) ?>
                                        <div class="text-xs muted mono"><?= e($r['cid_number']) ?></div>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="mono text-xs"><?= e(mask_secret($r['secret'])) ?></span>
                                </td>
                                <td>
                                    <?php if ($r['vm_id']): ?>
                                        <a href="/voicemail" class="badge green" title="Open voicemail settings">On</a>
                                    <?php else: ?>
                                        <span class="badge gray">Off</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($r['device_mac'])): ?>
                                        <span class="badge blue">
                                            <?= e(ucfirst((string) $r['device_vendor'])) ?>
                                        </span>
                                        <div class="text-xs muted mono"><?= e(human_mac($r['device_mac'])) ?></div>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$r['enabled']): ?>
                                        <span class="badge gray"><span class="dot"></span>Disabled</span>
                                    <?php elseif (!$registrationAvailable): ?>
                                        <span class="badge gray"><span class="dot"></span>Unknown</span>
                                    <?php elseif ($r['registration_status'] !== null): ?>
                                        <span class="badge green" title="Asterisk contact status: <?= e($r['registration_status']) ?>"><span class="dot"></span>Registered</span>
                                    <?php else: ?>
                                        <span class="badge amber"><span class="dot"></span>Unregistered</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['enabled']): ?>
                                        <span class="badge green"><span class="dot"></span>Enabled</span>
                                    <?php else: ?>
                                        <span class="badge gray"><span class="dot"></span>Disabled</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="/extensions/<?= (int) $r['id'] ?>/edit" class="btn btn-sm">Edit</a>
                                    <form method="post" action="/extensions/<?= (int) $r['id'] ?>/regenerate-secret"
                                          data-confirm-btn="Regenerate the SIP password for <?= e($r['extension']) ?>? The phone must be reprovisioned.">
                                        <?= csrf() ?>
                                        <button class="btn btn-sm btn-ghost" title="Regenerate SIP password">
                                            <?= $this->fragment('icons/refresh') ?>
                                        </button>
                                    </form>
                                    <form method="post" action="/extensions/<?= (int) $r['id'] ?>">
                                        <?= csrf() ?>
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button class="btn btn-sm btn-ghost"
                                                style="color:var(--danger-500)"
                                                data-confirm-btn="Delete extension <?= e($r['extension']) ?>? This also removes its voicemail box and queue/ring-group memberships.">
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

    <?php if ($pages > 1): ?>
        <div class="card-foot">
            <?= \App\Core\View::fragment('partials/pagination', [
                'page' => $page, 'pages' => $pages,
                'base' => '/extensions' . ($q ? '?q=' . urlencode($q) : ''),
            ]) ?>
            <span class="muted text-sm">Page <?= $page ?> of <?= $pages ?></span>
        </div>
    <?php endif; ?>
</div>
