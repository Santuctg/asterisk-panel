<?php
/** Voicemail boxes list. Expects: $rows */
require dirname(__DIR__) . '/partials/fields.php';
?>

<div class="page-head">
    <div>
        <h1>Voicemail</h1>
        <div class="subtitle">Mailboxes and voicemail-to-email delivery</div>
    </div>
    <div class="page-actions">
        <a href="/voicemail/create" class="btn btn-primary">
            <?= $this->fragment('icons/plus') ?> New mailbox
        </a>
    </div>
</div>

<div class="flash flash-info">
    <span class="flash-icon"><?= $this->fragment('icons/info') ?></span>
    <span class="flash-msg">
        Emails are sent by Asterisk itself through the <code>mailcmd</code> hook.
        To configure the SMTP/relay command, see <code>scripts/asterisk-mail.php</code>
        and <code>docs/asterisk-setup.md</code>.
    </span>
</div>

<div class="card">
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon'   => 'mail',
                'title'  => 'No voicemail boxes yet',
                'message'=> 'Create a mailbox to let callers leave messages — and have them emailed out.',
                'action_html' => '<a class="btn btn-primary" href="/voicemail/create">Create mailbox</a>',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Ext</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>PIN</th>
                            <th>Attach</th>
                            <th>Envelope</th>
                            <th>Status</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><strong class="mono"><?= e($r['ext']) ?></strong></td>
                                <td><?= e($r['name']) ?></td>
                                <td>
                                    <?php if (!empty($r['email'])): ?>
                                        <?= e($r['email']) ?>
                                    <?php else: ?>
                                        <span class="muted">No email set</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="mono"><?= e($r['pin']) ?></span></td>
                                <td>
                                    <?= $r['attach']
                                        ? '<span class="badge green">Audio</span>'
                                        : '<span class="badge gray">Notify only</span>' ?>
                                </td>
                                <td><?= $r['envelope'] ? '<span class="badge blue">On</span>' : '<span class="badge gray">Off</span>' ?></td>
                                <td>
                                    <?= $r['enabled']
                                        ? '<span class="badge green"><span class="dot"></span>Active</span>'
                                        : '<span class="badge gray"><span class="dot"></span>Disabled</span>' ?>
                                </td>
                                <td class="actions">
                                    <a href="/voicemail/<?= (int) $r['id'] ?>/edit" class="btn btn-sm">Edit</a>
                                    <form method="post" action="/voicemail/<?= (int) $r['id'] ?>">
                                        <?= csrf() ?>
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                data-confirm-btn="Delete the voicemail box for <?= e($r['ext']) ?>?">
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
