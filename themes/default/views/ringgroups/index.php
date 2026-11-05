<?php
/** Ring groups list. Expects: $rows */
require dirname(__DIR__) . '/partials/fields.php';

$strategies = [
    'ringall'    => 'Ring all simultaneously',
    'leastrecent'=> 'Least recently answered',
    'random'     => 'Random',
    'wrandom'    => 'Weighted random',
    'seqfixed'   => 'Sequential (fixed order)',
];
?>

<div class="page-head">
    <div>
        <h1>Ring Groups</h1>
        <div class="subtitle">Groups of extensions that ring together</div>
    </div>
    <div class="page-actions">
        <a href="/ring-groups/create" class="btn btn-primary">
            <?= $this->fragment('icons/plus') ?> New ring group
        </a>
    </div>
</div>

<div class="card">
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon'   => 'users',
                'title'  => 'No ring groups yet',
                'message'=> 'Ring groups let a single number ring several phones at once.',
                'action_html' => '<a class="btn btn-primary" href="/ring-groups/create">Create ring group</a>',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Ext</th>
                            <th>Name</th>
                            <th>Strategy</th>
                            <th>Members</th>
                            <th class="num">Timeout</th>
                            <th>Announcement</th>
                            <th>Status</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><strong class="mono"><?= e($r['extension']) ?></strong></td>
                                <td><?= e($r['name']) ?></td>
                                <td>
                                    <span class="badge violet">
                                        <?= e($strategies[$r['strategy']] ?? $r['strategy']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (empty($r['members'])): ?>
                                        <span class="muted">No members</span>
                                    <?php else: ?>
                                        <div class="flex gap-2" style="flex-wrap:wrap">
                                            <?php foreach (array_slice($r['members'], 0, 6) as $m): ?>
                                                <span class="badge gray mono"><?= e($m['extension']) ?></span>
                                            <?php endforeach; ?>
                                            <?php if (count($r['members']) > 6): ?>
                                                <span class="badge gray">+<?= count($r['members']) - 6 ?> more</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="num mono"><?= (int) $r['timeout'] ?>s</td>
                                <td><?= !empty($r['announcement_name']) ? e($r['announcement_name']) : '<span class="muted">—</span>' ?></td>
                                <td>
                                    <?= $r['enabled']
                                        ? '<span class="badge green"><span class="dot"></span>Active</span>'
                                        : '<span class="badge gray"><span class="dot"></span>Disabled</span>' ?>
                                </td>
                                <td class="actions">
                                    <a href="/ring-groups/<?= (int) $r['id'] ?>/edit" class="btn btn-sm">Edit</a>
                                    <form method="post" action="/ring-groups/<?= (int) $r['id'] ?>">
                                        <?= csrf() ?>
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                data-confirm-btn="Delete ring group <?= e($r['name']) ?>?">
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
