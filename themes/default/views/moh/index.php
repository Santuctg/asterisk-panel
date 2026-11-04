<?php
/**
 * Music on hold classes. Expects: $rows
 */
require dirname(__DIR__) . '/partials/fields.php';
?>

<div class="page-head">
    <div>
        <h1>Music on Hold</h1>
        <div class="subtitle">Audio classes played to callers on hold or in a queue</div>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-modal-open="mohModal">
            <?= $this->fragment('icons/plus') ?> New class
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon'   => 'music',
                'title'  => 'No music-on-hold classes',
                'message'=> 'Create a class pointing at a directory of audio files under the Asterisk MOH root.',
                'action_html' => '<button class="btn btn-primary" data-modal-open="mohModal">Create class</button>',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Directory</th>
                            <th class="num">Files</th>
                            <th>Random</th>
                            <th class="actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><strong class="mono"><?= e($r['name']) ?></strong></td>
                                <td><code class="text-xs"><?= e($r['resolved_dir']) ?></code></td>
                                <td class="num">
                                    <?php if (empty($r['files'])): ?>
                                        <span class="badge amber">No files found</span>
                                    <?php else: ?>
                                        <span class="badge blue"><?= count($r['files']) ?> file(s)</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $r['random'] ? '<span class="badge green">Yes</span>' : '<span class="badge gray">No</span>' ?></td>
                                <td class="actions">
                                    <button class="btn btn-sm" data-modal-open="mohModal"
                                            data-edit='<?= e(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                                    <form method="post" action="/moh/<?= (int) $r['id'] ?>">
                                        <?= csrf() ?><input type="hidden" name="_method" value="DELETE">
                                        <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                data-confirm-btn="Delete music class <?= e($r['name']) ?>?">
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

<div class="modal-overlay" id="mohModal" hidden>
    <div class="modal">
        <form method="post" action="/moh" id="mohForm" enctype="multipart/form-data">
            <?= csrf() ?>
            <div class="modal-head">
                <h2 id="mohTitle">New music-on-hold class</h2>
                <button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <?php echo field_text('name', 'Class name', '', [
                        'required' => true, 'placeholder' => 'e.g. moh_sales',
                        'hint' => 'Letters, numbers, dashes and underscores only.',
                    ]); ?>
                    <?php echo field_text('directory', 'Audio directory (optional)', '', [
                        'hint' => 'Defaults to <MOH root>/<class name>.',
                    ]); ?>
                    <div class="field span-2">
                        <label for="mohAudio">Audio files</label>
                        <input id="mohAudio" type="file" name="audio[]" multiple
                               accept=".wav,.gsm,.ulaw,.alaw,.g722,.sln,.sln16,.sln48,.sln96,.sln192,.mp3,.ogg">
                        <div class="hint">Upload one or more audio files (maximum 25 MB each).</div>
                    </div>
                    <div class="span-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                        <?php echo field_check('random', 'Randomize playback order', true); ?>
                        <?php echo field_check('shuffle', 'Shuffle', false); ?>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="mohSubmit">Create class</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal  = document.getElementById('mohModal');
    var form   = document.getElementById('mohForm');
    var title  = document.getElementById('mohTitle');
    var submit = document.getElementById('mohSubmit');

    document.querySelectorAll('[data-modal-open="mohModal"][data-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
        var d = JSON.parse(btn.getAttribute('data-edit'));
        form.action = '/moh/' + d.id;
        title.textContent = 'Edit music class';
        submit.textContent = 'Save changes';
        form.elements['name'].value = d.name;
        form.elements['directory'].value = d.directory || '';
        form.elements['random'].checked = Number(d.random) === 1;
        form.elements['shuffle'].checked = Number(d.shuffle) === 1;
        });
    });

    document.querySelectorAll('[data-modal-open="mohModal"]').forEach(function (opener) {
        if (opener.hasAttribute('data-edit')) return;
        opener.addEventListener('click', function () {
            form.reset(); form.action = '/moh';
            title.textContent = 'New music-on-hold class';
            submit.textContent = 'Create class';
        });
    });
})();
</script>
