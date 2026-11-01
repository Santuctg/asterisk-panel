<?php
/**
 * Conference rooms. Expects: $rows, $live, $mohClasses
 */
require dirname(__DIR__) . '/partials/fields.php';

$liveNames = array_column((array) $live, 'conference');
$mohOpts = ['default' => 'default'];
foreach ((array) $mohClasses as $c) { $mohOpts[$c['name']] = $c['name']; }
?>

<div class="page-head">
    <div>
        <h1>Conferences</h1>
        <div class="subtitle">Audio conference rooms (ConfBridge)</div>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-modal-open="confModal">
            <?= $this->fragment('icons/plus') ?> New conference
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon'   => 'conf',
                'title'  => 'No conference rooms',
                'message'=> 'Create a room so participants can dial in with a PIN.',
                'action_html' => '<button class="btn btn-primary" data-modal-open="confModal">Create conference</button>',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Name</th>
                            <th>PIN</th>
                            <th>Admin PIN</th>
                            <th>Max</th>
                            <th>Record</th>
                            <th>Status</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php $isLive = in_array($r['extension'], $liveNames, true); ?>
                            <tr>
                                <td><strong class="mono"><?= e($r['extension']) ?></strong></td>
                                <td><?= e($r['name']) ?></td>
                                <td><span class="mono"><?= e($r['pin']) ?></span></td>
                                <td><span class="mono"><?= e($r['admin_pin'] ?: '—') ?></span></td>
                                <td class="num"><?= (int) $r['max_members'] > 0 ? (int) $r['max_members'] : '∞' ?></td>
                                <td><?= $r['record'] ? '<span class="badge amber">On</span>' : '<span class="badge gray">Off</span>' ?></td>
                                <td>
                                    <?php if ($isLive): ?>
                                        <span class="badge green"><span class="dot"></span>In session</span>
                                    <?php else: ?>
                                        <span class="badge gray"><span class="dot"></span>Idle</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <button class="btn btn-sm" data-modal-open="confModal"
                                            data-edit='<?= e(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                                    <form method="post" action="/conferences/<?= (int) $r['id'] ?>">
                                        <?= csrf() ?><input type="hidden" name="_method" value="DELETE">
                                        <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                data-confirm-btn="Delete conference <?= e($r['name']) ?>?">
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

<div class="modal-overlay" id="confModal" hidden>
    <div class="modal">
        <form method="post" action="/conferences" id="confForm">
            <?= csrf() ?>
            <div class="modal-head">
                <h2 id="confTitle">New conference</h2>
                <button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <?php echo field_text('extension', 'Room number', '', ['required' => true]); ?>
                    <?php echo field_text('name', 'Room name', '', ['required' => true]); ?>
                    <?php echo field_text('pin', 'Participant PIN', '', [
                        'required' => true, 'hint' => '4–10 digits, dialed to join.',
                    ]); ?>
                    <?php echo field_text('admin_pin', 'Admin PIN', '', [
                        'hint' => 'Optional. Grants moderation (kick, lock, etc.).',
                    ]); ?>
                    <?php echo field_text('max_members', 'Max participants', 0, [
                        'type' => 'number', 'hint' => '0 = unlimited.',
                    ]); ?>
                    <?php echo field_select('moh_class', 'Music on hold class', $mohOpts, 'default'); ?>
                    <div class="span-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <?php echo field_check('record', 'Record conferences', false); ?>
                        <?php echo field_check('enabled', 'Room active', true); ?>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="confSubmit">Create conference</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal  = document.getElementById('confModal');
    var form   = document.getElementById('confForm');
    var title  = document.getElementById('confTitle');
    var submit = document.getElementById('confSubmit');

    modal.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-edit]');
        if (!btn) return;
        var d = JSON.parse(btn.getAttribute('data-edit'));
        form.action = '/conferences/' + d.id;
        title.textContent = 'Edit conference';
        submit.textContent = 'Save changes';
        ['extension','name','pin','admin_pin','max_members','moh_class'].forEach(function (k) {
            var el = form.elements[k];
            if (el && d[k] !== undefined && d[k] !== null) el.value = d[k];
        });
        form.elements['record'].checked = Number(d.record) === 1;
        form.elements['enabled'].checked = Number(d.enabled) === 1;
    });

    document.querySelectorAll('[data-modal-open="confModal"]').forEach(function (opener) {
        if (opener.hasAttribute('data-edit')) return;
        opener.addEventListener('click', function () {
            form.reset(); form.action = '/conferences';
            title.textContent = 'New conference'; submit.textContent = 'Create conference';
        });
    });
})();
</script>
