<?php
/**
 * Call parking lots. Expects: $rows, $parked (live parked calls)
 */
require dirname(__DIR__) . '/partials/fields.php';

$toneOpts = ['beep' => 'Beep', 'ring' => 'Ring', 'vertex' => 'Vertex', 'none' => 'None'];
?>

<div class="page-head">
    <div>
        <h1>Call Parking</h1>
        <div class="subtitle">Park calls in an orbit slot and pick them up from any phone</div>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-modal-open="parkModal">
            <?= $this->fragment('icons/plus') ?> New parking lot
        </button>
    </div>
</div>

<?php if (!empty($parked)): ?>
    <div class="card mb-4">
        <div class="card-head">
            <h2>Currently parked calls</h2>
            <span class="badge green"><span class="dot"></span>Live</span>
        </div>
        <div class="card-body tight">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Slot</th><th>Channel</th><th>Timeout</th></tr></thead>
                    <tbody>
                        <?php foreach ($parked as $p): ?>
                            <tr>
                                <td><strong class="mono"><?= e($p['park']) ?></strong></td>
                                <td class="mono"><?= e($p['channel']) ?></td>
                                <td class="mono"><?= e($p['timeout']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon'   => 'park',
                'title'  => 'No parking lots',
                'message'=> 'Create a lot so callers can be parked and picked up from another extension.',
                'action_html' => '<button class="btn btn-primary" data-modal-open="parkModal">Create parking lot</button>',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Ext</th>
                            <th>Name</th>
                            <th>Orbit slots</th>
                            <th class="num">Timeout</th>
                            <th>Return to</th>
                            <th>Courtesy</th>
                            <th>Status</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><strong class="mono"><?= e($r['extension']) ?></strong></td>
                                <td><?= e($r['name']) ?></td>
                                <td><span class="mono"><?= e($r['parked_range']) ?></span></td>
                                <td class="num mono"><?= (int) $r['timeout'] ?>s</td>
                                <td><?= !empty($r['return_extension']) ? '<span class="mono">' . e($r['return_extension']) . '</span>' : '<span class="muted">—</span>' ?></td>
                                <td><span class="badge gray"><?= e($r['courtesy_tone']) ?></span></td>
                                <td>
                                    <?= $r['enabled']
                                        ? '<span class="badge green"><span class="dot"></span>Active</span>'
                                        : '<span class="badge gray"><span class="dot"></span>Disabled</span>' ?>
                                </td>
                                <td class="actions">
                                    <button class="btn btn-sm" data-modal-open="parkModal"
                                            data-edit='<?= e(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                                        Edit
                                    </button>
                                    <form method="post" action="/parking/<?= (int) $r['id'] ?>">
                                        <?= csrf() ?>
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                data-confirm-btn="Delete parking lot <?= e($r['name']) ?>?">
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

<!-- Create/edit modal -->
<div class="modal-overlay" id="parkModal" hidden>
    <div class="modal lg">
        <form method="post" action="" id="parkForm">
            <?= csrf() ?>
            <input type="hidden" name="_method" value="POST" id="parkMethod">
            <div class="modal-head">
                <h2 id="parkTitle">New parking lot</h2>
                <button type="button" class="icon-btn" data-modal-close aria-label="Close"><?= $this->fragment('icons/x') ?></button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <?php echo field_text('extension', 'Park extension', '', ['required' => true]); ?>
                    <?php echo field_text('name', 'Lot name', '', ['required' => true, 'placeholder' => 'e.g. Main parking']); ?>
                    <?php echo field_text('parked_range', 'Orbit slot range', '701-750', [
                        'required' => true, 'hint' => 'Slots callers wait in, e.g. 701-750.',
                    ]); ?>
                    <?php echo field_text('return_extension', 'Return to extension', '', [
                        'hint' => 'Where a parked call goes if not picked up in time.',
                    ]); ?>
                    <?php echo field_text('timeout', 'Park timeout (seconds)', 45, ['type' => 'number']); ?>
                    <?php echo field_select('courtesy_tone', 'Courtesy tone', $toneOpts, 'beep'); ?>
                    <div class="span-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <?php echo field_check('allow_transfer', 'Allow transfers', true); ?>
                        <?php echo field_check('allow_repark', 'Allow re-parking', true); ?>
                        <?php echo field_check('allow_hangup', 'Allow hangup from slot', false); ?>
                        <?php echo field_check('play_sounds', 'Play confirmation sounds', true); ?>
                        <?php echo field_check('record', 'Record parked calls', false); ?>
                        <?php echo field_check('enabled', 'Lot active', true); ?>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="parkSubmit">Create lot</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var form   = document.getElementById('parkForm');
    var modal  = document.getElementById('parkModal');
    var title  = document.getElementById('parkTitle');
    var submit = document.getElementById('parkSubmit');
    var method = document.getElementById('parkMethod');

    // Populate the modal for editing when a row's Edit button is clicked.
    modal.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-edit]');
        if (!btn) return;

        var data = JSON.parse(btn.getAttribute('data-edit'));
        form.action = '/parking/' + data.id;
        method.value = 'POST';
        title.textContent = 'Edit parking lot';
        submit.textContent = 'Save changes';

        ['extension', 'name', 'parked_range', 'return_extension', 'timeout', 'courtesy_tone'].forEach(function (k) {
            var el = form.elements[k];
            if (el && data[k] !== undefined && data[k] !== null) el.value = data[k];
        });

        [['allow_transfer', 1], ['allow_repark', 1], ['allow_hangup', 0],
         ['play_sounds', 1], ['record', 0], ['enabled', 1]].forEach(function (pair) {
            var el = form.elements[pair[0]];
            if (el) el.checked = Number(data[pair[0]]) === pair[1];
        });
    });

    // Reset to "create" mode whenever the modal is opened fresh.
    document.querySelectorAll('[data-modal-open="parkModal"]').forEach(function (opener) {
        if (opener.hasAttribute('data-edit')) return;
        opener.addEventListener('click', function () {
            form.reset();
            form.action = '/parking';
            method.value = 'POST';
            title.textContent = 'New parking lot';
            submit.textContent = 'Create lot';
        });
    });
})();
</script>
