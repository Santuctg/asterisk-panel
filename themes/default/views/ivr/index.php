<?php
/**
 * IVR menus list. Expects: $rows (keys decoded with labels), $destinations
 */
require dirname(__DIR__) . '/partials/fields.php';
?>

<div class="page-head">
    <div>
        <h1>IVR Menus</h1>
        <div class="subtitle">Auto-attendants that route calls by key press</div>
    </div>
    <div class="page-actions">
        <a href="/ivr/create" class="btn btn-primary">
            <?= $this->fragment('icons/plus') ?> New IVR menu
        </a>
    </div>
</div>

<div class="card">
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon'   => 'menu',
                'title'  => 'No IVR menus yet',
                'message'=> 'Create an auto-attendant to greet callers and route them by key press.',
                'action_html' => '<a class="btn btn-primary" href="/ivr/create">Create IVR menu</a>',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Ext</th>
                            <th>Name</th>
                            <th>Greeting</th>
                            <th>Keys</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><strong class="mono"><?= e($r['extension']) ?></strong></td>
                                <td>
                                    <?= e($r['name']) ?>
                                    <div class="text-xs muted">
                                        Digit timeout <?= (int) $r['timeout'] ?>s ·
                                        Response <?= (int) $r['response_timeout'] ?>s
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($r['greeting'])): ?>
                                        <code class="text-xs"><?= e($r['greeting']) ?></code>
                                    <?php else: ?>
                                        <span class="muted">Silence</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($r['keys'])): ?>
                                        <span class="muted">No keys mapped</span>
                                    <?php else: ?>
                                        <div class="flex gap-2" style="flex-wrap:wrap">
                                            <?php foreach ($r['keys'] as $k => $dest): ?>
                                                <span class="badge blue" title="<?= e($dest['label'] ?? '') ?>">
                                                    <?= e($k) ?> → <?= e($dest['label'] ?? '—') ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="/ivr/<?= (int) $r['id'] ?>/edit" class="btn btn-sm">Edit</a>
                                    <form method="post" action="/ivr/<?= (int) $r['id'] ?>">
                                        <?= csrf() ?>
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                data-confirm-btn="Delete IVR menu <?= e($r['name']) ?>?">
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
