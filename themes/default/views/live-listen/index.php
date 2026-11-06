<div class="page-head"><div>
    <h1>Live Listen</h1>
    <div class="subtitle">Listen-only monitoring of connected calls in the selected tenant.</div>
</div><div class="page-actions"><a href="/live-listen" class="btn">Refresh calls</a></div></div>

<div class="notice info mb-4">
    Select a connected call and listen directly in this browser. Enter that extension's SIP password when prompted;
    it stays in browser memory and is never sent to the panel. Whisper and barge are disabled.
</div>
<p id="listen-status" role="status" aria-live="polite"></p>
<button id="listen-stop" type="button" class="btn" hidden>Stop listening</button>
<audio id="listen-audio" autoplay controls hidden></audio>
<div id="listen-credentials" class="card mb-4" hidden><div class="card-body">
    <h3>Connect browser listener</h3>
    <p class="muted">Extension <strong id="listen-extension-label"></strong> — enter its SIP password.</p>
    <div class="form-grid cols-2"><div class="field"><label for="listen-secret">SIP password</label><input id="listen-secret" type="password" autocomplete="off"></div>
    <div class="form-actions"><button type="button" class="btn btn-primary" id="listen-connect">Start listening</button><button type="button" class="btn" id="listen-cancel">Cancel</button></div></div>
</div></div>

<div class="card"><div class="card-head"><h2>Connected calls</h2></div><div class="card-body tight">
    <?php if (!$channels): ?>
        <p>No connected tenant extension calls found. Refresh after a call connects.</p>
    <?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Extension</th><th>Caller</th><th>Duration</th><th>Listen</th></tr></thead><tbody>
        <?php foreach ($channels as $channel): ?>
            <tr><td><strong><?= e($channel['listen_extension']) ?></strong></td>
                <td><?= e($channel['connected'] ?: $channel['caller']) ?></td><td class="mono"><?= e($channel['duration']) ?></td>
                <td><?php if ($browserReady): ?><button type="button" class="btn btn-primary listen-browser" data-channel="<?= e($channel['name']) ?>" data-extension="<?= e($channel['listen_extension']) ?>">Listen in browser</button><?php else: ?><span class="muted">HTTPS required</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>
</div></div>

<?php if ($browserReady): ?>
<input type="hidden" id="listen-context" value="<?= e($context) ?>">
<script type="module" src="<?= e(asset('assets/js/live-listen.js')) ?>"></script>
<?php endif; ?>
