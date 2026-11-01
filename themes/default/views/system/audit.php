<?php
/**
 * Audit log. Expects: $rows, $page, $pages, $total
 */
require dirname(__DIR__) . '/partials/fields.php';

$actionTone = static function (string $action): string {
    return match (true) {
        str_starts_with($action, 'auth.login')    => 'green',
        str_starts_with($action, 'auth.logout')   => 'gray',
        str_contains($action, '.delete')          => 'red',
        str_contains($action, '.create')          => 'blue',
        str_contains($action, 'password')         => 'amber',
        default                                   => 'violet',
    };
};
?>

<div class="page-head">
    <div>
        <h1>Audit Log</h1>
        <div class="subtitle">Who did what, and when</div>
    </div>
</div>

<div class="card">
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon' => 'clock', 'title' => 'No audit entries',
                'message' => 'Actions performed through the panel will be recorded here.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Entity</th>
                            <th>IP</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="nowrap muted text-xs"><?= e(format_date($r['created_at'])) ?></td>
                                <td><?= e($r['username'] ?? 'system') ?></td>
                                <td><span class="badge <?= $actionTone($r['action']) ?>"><?= e($r['action']) ?></span></td>
                                <td class="muted text-xs">
                                    <?= e($r['entity'] ?? '—') ?>
                                    <?php if (!empty($r['entity_id'])): ?>
                                        <span class="mono">#<?= (int) $r['entity_id'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="mono text-xs muted"><?= e($r['ip'] ?? '—') ?></td>
                                <td class="text-xs muted mono" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                    <?= e($r['detail'] ?? '—') ?>
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
                'page' => $page, 'pages' => $pages, 'base' => '/system/audit',
            ]) ?>
            <span class="muted text-sm">Page <?= $page ?> of <?= $pages ?></span>
        </div>
    <?php endif; ?>
</div>
