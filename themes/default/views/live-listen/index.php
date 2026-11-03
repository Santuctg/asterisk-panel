<?php
/** Supervisor console: no SIP secret is ever embedded by the server. */
?>
<div class="page-head"><div>
    <h1>Live Listen</h1>
    <div class="subtitle">Listen-only monitoring of connected calls in the selected tenant.</div>
</div><div class="page-actions"><a href="/live-listen" class="btn">Refresh calls</a></div></div>

<div class="card mb-4"><div class="card-body">
    <p>Choose a supervisor extension in this tenant. <strong>Phone</strong> rings that extension.
    <strong>Browser</strong> registers its separate WebRTC identity and rings this tab. Neither mode allows whisper or barge.</p>
    <label for="listen-supervisor">Supervisor extension</label>
    <select id="listen-supervisor" required>
        <option value="">Select extension</option>
        <?php foreach ($extensions as $ext): ?>
            <option value="<?= e($ext['extension']) ?>"><?= e($ext['extension'] . ' — ' . $ext['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($browserReady): ?>
        <label for="listen-secret">Browser SIP password (extension secret; not saved)</label>
        <input id="listen-secret" type="password" autocomplete="off" placeholder="Enter extension SIP secret for Browser Listen">
    <?php else: ?>
        <p>Browser Listen requires a production HTTPS URL and the Asterisk WSS proxy.</p>
    <?php endif; ?>
    <p id="listen-status" role="status" aria-live="polite"></p>
    <button id="listen-stop" type="button" class="btn" hidden>Stop browser listening</button>
    <audio id="listen-audio" autoplay controls hidden></audio>
</div></div>

<div class="card"><div class="card-head"><h2>Connected calls</h2></div><div class="card-body tight">
    <?php if (!$channels): ?>
        <p>No connected tenant extension channels found. Check AMI access, or refresh after a call connects.</p>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Channel</th><th>Caller</th><th>Duration</th><th>Listen</th></tr></thead><tbody>
        <?php foreach ($channels as $channel): ?>
            <tr><td class="mono text-xs"><?= e($channel['name']) ?></td>
                <td><?= e($channel['caller']) ?></td><td><?= e($channel['duration']) ?></td>
                <td><button type="button" class="btn listen-phone" data-channel="<?= e($channel['name']) ?>">Phone</button>
                    <?php if ($browserReady): ?><button type="button" class="btn listen-browser" data-channel="<?= e($channel['name']) ?>">Browser</button><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div></div>

<?php if ($browserReady): ?>
<input type="hidden" id="listen-context" value="<?= e($context) ?>">
<script type="module" src="<?= e(asset('assets/js/live-listen.js')) ?>"></script>
<?php else: ?>
<script>
document.querySelectorAll('.listen-phone').forEach(button => button.addEventListener('click', async () => {
    const extension = document.querySelector('#listen-supervisor').value;
    const status = document.querySelector('#listen-status');
    if (!extension) { status.textContent = 'Select a supervisor extension first.'; return; }
    try {
        const response = await fetch('/live-listen/start', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:new URLSearchParams({ _token: '<?= e(\App\Core\Csrf::token()) ?>', mode:'phone', supervisor:extension, channel:button.dataset.channel })});
        const data = await response.json(); status.textContent = data.message || data.error || 'Request failed.';
    } catch (_) { status.textContent = 'Could not contact the panel.'; }
}));
</script>
<?php endif; ?>
