<?php
/**
 * Call center: queues + agents + live status.
 * Expects: $rows, $live, $extensions, $mohClasses
 */
require dirname(__DIR__) . '/partials/fields.php';

$strategies = [
    'ringall'     => 'Ring all',
    'leastrecent' => 'Least recently answered',
    'fewest'      => 'Fewest calls taken',
    'random'      => 'Random',
    'rrmemory'    => 'Round-robin (memory)',
    'linear'      => 'Linear (fixed order)',
];

// Index live queue data by name for a quick lookup.
$liveByName = [];
foreach ((array) $live as $lq) {
    $liveByName[$lq['name']] = $lq;
}
?>

<div class="page-head">
    <div>
        <h1>Call Center</h1>
        <div class="subtitle">Queues that distribute calls to groups of agents</div>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-modal-open="queueModal">
            <?= $this->fragment('icons/plus') ?> New queue
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon'   => 'headset',
                'title'  => 'No queues yet',
                'message'=> 'Queues hold callers and distribute them to agents as they become available.',
                'action_html' => '<button class="btn btn-primary" data-modal-open="queueModal">Create queue</button>',
            ]) ?>
        <?php else: ?>
            <?php foreach ($rows as $q): ?>
                <?php $lq = $liveByName[$q['extension']] ?? $liveByName[$q['name']] ?? null; ?>
                <div class="card-head">
                    <div class="flex items-center gap-3">
                        <h2><?= e($q['name']) ?></h2>
                        <span class="badge gray mono"><?= e($q['extension']) ?></span>
                        <span class="badge violet"><?= e($strategies[$q['strategy']] ?? $q['strategy']) ?></span>
                        <?php if (!$q['enabled']): ?>
                            <span class="badge gray"><span class="dot"></span>Disabled</span>
                        <?php elseif ($lq !== null): ?>
                            <span class="badge green"><span class="dot"></span>Live</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex gap-2">
                        <button class="btn btn-sm" data-modal-open="queueModal"
                                data-edit='<?= e(json_encode($q, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                        <form method="post" action="/call-center/<?= (int) $q['id'] ?>">
                            <?= csrf() ?><input type="hidden" name="_method" value="DELETE">
                            <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                    data-confirm-btn="Delete queue <?= e($q['name']) ?>?">
                                <?= $this->fragment('icons/trash') ?>
                            </button>
                        </form>
                    </div>
                </div>
                <div class="card-body">
                    <div class="grid grid-4 mb-3">
                        <div><div class="text-xs muted">Ring timeout</div><strong class="mono"><?= (int) $q['timeout'] ?>s</strong></div>
                        <div><div class="text-xs muted">Max wait</div><strong class="mono"><?= (int) $q['max_wait'] ?>s</strong></div>
                        <div><div class="text-xs muted">Wrap-up</div><strong class="mono"><?= (int) $q['wrapup'] ?>s</strong></div>
                        <div><div class="text-xs muted">Music on hold</div><strong class="mono"><?= e($q['moh_class']) ?></strong></div>
                        <div><div class="text-xs muted">Announcement</div><strong><?= e($q['announcement_name'] ?? '—') ?></strong></div>
                    </div>

                    <h3 class="mb-2">Agents</h3>
                    <?php if (empty($q['agents'])): ?>
                        <p class="muted mb-0">No agents assigned. Edit the queue to add extensions as agents.</p>
                    <?php else: ?>
                        <div class="flex gap-2" style="flex-wrap:wrap">
                            <?php foreach ($q['agents'] as $ag): ?>
                                <span class="badge <?= $ag['paused'] ? 'amber' : 'green' ?>">
                                    <span class="dot"></span>
                                    <span class="mono"><?= e($ag['extension']) ?></span> — <?= e($ag['name']) ?>
                                    <?php if ((int) $ag['penalty'] > 0): ?>
                                        <em class="text-xs">(penalty <?= (int) $ag['penalty'] ?>)</em>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Queue create/edit modal -->
<div class="modal-overlay" id="queueModal" hidden>
    <div class="modal lg">
        <form method="post" action="/call-center" id="queueForm">
            <?= csrf() ?>
            <div class="modal-head">
                <h2 id="queueTitle">New queue</h2>
                <button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <?php echo field_text('extension', 'Queue number', '', ['required' => true]); ?>
                    <?php echo field_text('name', 'Queue name', '', ['required' => true, 'placeholder' => 'e.g. Support']); ?>
                    <?php echo field_select('strategy', 'Ring strategy', $strategies, 'ringall', ['required' => true]); ?>
                    <?php
                    $mohOpts = [];
                    foreach ((array) $mohClasses as $c) { $mohOpts[$c['name']] = $c['name']; }
                    echo field_select('moh_class', 'Music on hold class', $mohOpts + ['default' => 'default'], 'default');
                    ?>
                    <?php
                    $announcementOpts = [];
                    foreach ((array) ($announcements ?? []) as $a) { $announcementOpts[(int) $a['id']] = $a['name']; }
                    echo field_select('announcement_id', 'Custom prompt', $announcementOpts, null, [
                        'placeholder' => 'No custom prompt',
                        'hint' => 'Plays completely before the caller enters the queue and cannot be skipped.',
                    ]);
                    ?>
                    <div class="field"><label>Prompt audio</label><a class="btn" href="/moh?add=announcement">Add Audio</a><span class="hint">Upload a new prompt, then return to select it.</span></div>
                    <?php echo field_text('timeout', 'Ring timeout (seconds)', 15, ['type' => 'number']); ?>
                    <?php echo field_text('max_wait', 'Max wait time (seconds)', 300, ['type' => 'number']); ?>
                    <?php echo field_text('service_level', 'Service level (%)', 60, ['type' => 'number']); ?>
                    <?php echo field_text('wrapup', 'Wrap-up time (seconds)', 5, ['type' => 'number']); ?>
                    <?php echo field_text('retry', 'Retry delay (seconds)', 5, ['type' => 'number']); ?>
                    <?php echo field_text('member_delay', 'Member delay (seconds)', 0, ['type' => 'number']); ?>
                    <?php echo field_text('agent_announce', 'Agent announcement file', '', [
                        'span' => true, 'hint' => 'Optional prompt played to the agent when a call arrives.',
                    ]); ?>

                    <div class="span-2">
                        <h3 class="mb-2">Agents</h3>
                        <?php if (empty($extensions)): ?>
                            <p class="muted">No extensions available. <a href="/extensions/create">Create one</a> first.</p>
                        <?php else: ?>
                            <div class="form-grid">
                                <?php foreach ($extensions as $ext): ?>
                                    <label class="check">
                                        <input type="checkbox" name="agents[]" value="<?= (int) $ext['id'] ?>">
                                        <span class="check-body">
                                            <strong class="mono"><?= e($ext['extension']) ?></strong>
                                            <span><?= e($ext['name']) ?></span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="span-2">
                        <?php echo field_check('enabled', 'Queue active', true); ?>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn" data-modal-close>Cancel</button>
                <button type="submit" class="btn btn-primary" id="queueSubmit">Create queue</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modal  = document.getElementById('queueModal');
    var form   = document.getElementById('queueForm');
    var title  = document.getElementById('queueTitle');
    var submit = document.getElementById('queueSubmit');

    document.querySelectorAll('[data-modal-open="queueModal"][data-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
        var d = JSON.parse(btn.getAttribute('data-edit'));
        form.action = '/call-center/' + d.id;
        title.textContent = 'Edit queue';
        submit.textContent = 'Save changes';

        ['extension','name','strategy','moh_class','timeout','max_wait',
         'service_level','wrapup','retry','member_delay','agent_announce','announcement_id'].forEach(function (k) {
            var el = form.elements[k];
            if (el && d[k] !== undefined && d[k] !== null) el.value = d[k];
        });
        form.elements['enabled'].checked = Number(d.enabled) === 1;
        });
    });

    document.querySelectorAll('[data-modal-open="queueModal"]').forEach(function (opener) {
        if (opener.hasAttribute('data-edit')) return;
        opener.addEventListener('click', function () {
            form.reset(); form.action = '/call-center';
            title.textContent = 'New queue'; submit.textContent = 'Create queue';
        });
    });
})();
</script>
