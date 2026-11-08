<?php require dirname(__DIR__) . '/partials/fields.php'; ?>
<div class="page-head">
    <div>
        <h1>Contacts</h1>
        <div class="subtitle"><?= (int) $total ?> tenant contact<?= $total === 1 ? '' : 's' ?> · available in the Web Phone phonebook</div>
    </div>
    <div class="page-actions">
        <a href="/contacts/import" class="btn"><?= $this->fragment('icons/download') ?> Import contacts</a>
        <a href="/contacts/create" class="btn btn-primary"><?= $this->fragment('icons/plus') ?> New contact</a>
    </div>
</div>

<div class="card">
    <div class="toolbar">
        <form method="get" action="/contacts" class="flex gap-2 items-center w-full">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, phone, email or company…" style="max-width:360px">
            <span class="spacer"></span><span class="muted text-sm"><?= (int) $total ?> total</span>
        </form>
    </div>
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon' => 'users', 'title' => 'No contacts yet',
                'message' => 'Add contacts manually or import an Excel-compatible CSV file.',
                'action_html' => '<a class="btn btn-primary" href="/contacts/create">Add contact</a>',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap"><table class="contacts-table">
                <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Company</th><th class="actions">Actions</th></tr></thead>
                <tbody><?php foreach ($rows as $r): ?><tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><span class="mono"><?= e($r['phone']) ?></span></td>
                    <td><?= $r['email'] !== '' ? e($r['email']) : '<span class="muted">—</span>' ?></td>
                    <td><?= $r['company'] !== '' ? e($r['company']) : '<span class="muted">—</span>' ?></td>
                    <td class="actions">
                        <a href="/contacts/<?= (int) $r['id'] ?>/edit" class="btn btn-sm">Edit</a>
                        <form method="post" action="/contacts/<?= (int) $r['id'] ?>"><?= csrf() ?><input type="hidden" name="_method" value="DELETE"><button class="btn btn-sm btn-ghost" style="color:var(--danger-500)" data-confirm-btn="Delete <?= e($r['name']) ?> from contacts?"><?= $this->fragment('icons/trash') ?></button></form>
                    </td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </div>
    <?php if ($pages > 1): ?><div class="card-foot">
        <?= \App\Core\View::fragment('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => '/contacts' . ($q ? '?q=' . urlencode($q) : '')]) ?>
        <span class="muted text-sm">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
    </div><?php endif; ?>
</div>
