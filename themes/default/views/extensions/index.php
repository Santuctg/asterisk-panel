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
                            <th>SIP login</th>
                            <th>Name</th>
                            <th>Caller ID</th>
                            <th>SIP password</th>
                            <th>Voicemail</th>
                            <th>Device</th>
                            <th>Registration</th>
                            <th>Latency</th>
                            <th>Online for</th>
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
                                    <span class="mono text-xs"><?= e($r['sip_username']) ?></span>
                                    <div class="text-xs muted mono">@<?= e($r['sip_realm']) ?></div>
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
                                        <?php if (!empty($r['active_devices'])): ?>
                                            <div class="text-xs muted"><?= (int) $r['active_devices'] ?> active device<?= (int) $r['active_devices'] === 1 ? '' : 's' ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge amber"><span class="dot"></span>Unregistered</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($r['latency_ms'])): ?>
                                        <span class="mono"><strong><?= e((string) $r['latency_ms']) ?></strong> ms</span>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($r['online_seconds'])): ?>
                                        <span class="mono"><?= e(format_duration((int) $r['online_seconds'])) ?></span>
                                        <div class="text-xs muted">since <?= e(format_date($r['online_since'], 'M j, g:i A')) ?></div>
                                    <?php else: ?>
                                        <span class="muted">—</span>
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

<div class="card" style="margin-top:14px">
    <div class="card-head">
        <h2>24-hour availability</h2>
        <span class="tl-legend">
            <span><i class="ok"></i>Registered</span>
            <span><i class="down"></i>Unregistered</span>
            <span><i class="off"></i>Disabled</span>
            <span>sampled every minute</span>
        </span>
    </div>
    <div class="card-body">
        <?php if (empty($timeline)): ?>
            <p class="muted mb-0">No extensions to monitor.</p>
        <?php endif; ?>
        <?php foreach ($timeline as $t): $segments = status_timeline($t['events']); $st = $t['state']; ?>
            <div class="tl-row">
                <div class="tl-label">
                    <strong class="mono"><?= e($t['extension']) ?></strong>
                    <span><?= e($t['name']) ?></span>
                </div>
                <div class="tl">
                    <?php foreach ($segments as $seg): ?>
                        <span class="tl-seg <?= e(status_timeline_class($seg['status'])) ?>"
                              style="left:<?= $seg['left'] ?>%;width:<?= $seg['width'] ?>%"
                              title="<?= e($seg['title']) ?>"></span>
                    <?php endforeach; ?>
                </div>
                <div class="tl-meta">
                    <?php if (!$t['enabled']): ?>
                        <span class="badge gray"><span class="dot"></span>Disabled</span>
                    <?php elseif (!$registrationAvailable): ?>
                        <span class="badge gray"><span class="dot"></span>Unknown</span>
                    <?php elseif ($st['status'] !== null): ?>
                        <span class="badge green"><span class="dot"></span>Registered</span>
                        <?php if ($st['devices'] > 0): ?> · <?= (int) $st['devices'] ?> device<?= (int) $st['devices'] === 1 ? '' : 's' ?><?php endif; ?>
                        <?php if ($st['rtt'] !== null): ?> · <span class="mono"><?= e((string) $st['rtt']) ?> ms</span><?php endif; ?>
                    <?php else: ?>
                        <span class="badge amber"><span class="dot"></span>Unregistered</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card" style="margin-top:14px">
    <div class="card-head">
        <h2>Event history</h2>
        <span class="muted text-sm">Latest 50 state periods</span>
    </div>
    <div class="card-body tight">
        <?php if (empty($monitorHistory)): ?>
            <p class="muted mb-0">No status changes recorded yet. History begins now and accumulates every minute.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Ext</th><th>Status</th><th>Started</th><th>Ended</th><th>Duration</th></tr></thead>
                    <tbody>
                    <?php foreach ($monitorHistory as $event):
                        $start = strtotime((string) $event['started_at']) ?: time();
                        $end = $event['ended_at'] ? (strtotime((string) $event['ended_at']) ?: time()) : time();
                        $registered = strcasecmp((string) $event['status'], 'Registered') === 0;
                    ?>
                        <tr>
                            <td><strong class="mono"><?= e($event['extension']) ?></strong></td>
                            <td><span class="badge <?= $registered ? 'green' : ((string) $event['status'] === 'Disabled' ? 'gray' : 'red') ?>"><span class="dot"></span><?= e($event['status']) ?></span></td>
                            <td><?= e(format_date($event['started_at'])) ?></td>
                            <td><?= $event['ended_at'] ? e(format_date($event['ended_at'])) : '<span class="badge blue">Current</span>' ?></td>
                            <td class="mono"><?= e(format_duration(max(0, $end - $start))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
