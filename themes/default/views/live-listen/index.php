<div class="page-head"><div>
    <h1>Live Listen</h1>
    <div class="subtitle">Listen-only monitoring of connected calls in the selected tenant.</div>
</div><div class="page-actions"><button type="button" class="btn" id="listen-refresh">Refresh calls</button></div></div>

<div class="notice info mb-4">
    Listen directly in this browser, or ring another tenant extension and listen after answering it.
    Browser SIP passwords stay in browser memory and are never sent to the panel. Whisper and barge are disabled.
</div>
<p id="listen-status" role="status" aria-live="polite"></p>
<p id="listen-refresh-status" class="muted text-sm" role="status" aria-live="polite">Calls update automatically.</p>
<button id="listen-stop" type="button" class="btn" hidden>Stop listening</button>
<audio id="listen-audio" autoplay playsinline controls hidden></audio>
<div id="listen-credentials" class="card mb-4" hidden><div class="card-body">
    <h3>Connect browser listener</h3>
    <p class="muted">Extension <strong id="listen-extension-label"></strong> — enter its SIP password.</p>
    <div class="form-grid cols-2"><div class="field"><label for="listen-secret">SIP password</label><input id="listen-secret" type="password" autocomplete="off"></div>
    <div class="form-actions"><button type="button" class="btn btn-primary" id="listen-connect">Start listening</button><button type="button" class="btn" id="listen-cancel">Cancel</button></div></div>
</div></div>

<div id="listen-extension-dialog" class="card mb-4" hidden><div class="card-body">
    <h3>Listen on an extension</h3>
    <p class="muted">Monitor extension <strong id="listen-target-label"></strong>. The selected listener phone will ring first.</p>
    <div class="form-grid cols-2"><div class="field"><label for="listen-extension">Listener extension</label><select id="listen-extension">
        <option value="">Select extension</option><?php foreach ($extensions as $extension): ?><option value="<?= e($extension['extension']) ?>"><?= e($extension['extension'] . ' · ' . ($extension['name'] ?: $extension['extension'])) ?></option><?php endforeach; ?>
    </select></div><div class="form-actions"><button type="button" class="btn btn-primary" id="listen-extension-start">Ring extension</button><button type="button" class="btn" id="listen-extension-cancel">Cancel</button></div></div>
</div></div>

<div class="card"><div class="card-head"><h2>Connected calls</h2></div><div class="card-body tight">
        <div class="table-wrap"><table><thead><tr><th>Extension</th><th>Caller</th><th>Duration</th><th>Listen</th></tr></thead><tbody id="listen-calls-body">
        <?php foreach ($channels as $channel): ?>
            <tr><td><strong><?= e($channel['listen_extension']) ?></strong></td>
                <td><?= e($channel['connected'] ?: $channel['caller']) ?></td><td class="mono"><?= e($channel['duration']) ?></td>
                <td><div class="form-actions"><?php if ($browserReady): ?><button type="button" class="btn btn-primary listen-browser" data-channel="<?= e($channel['name']) ?>" data-extension="<?= e($channel['listen_extension']) ?>">Browser</button><?php else: ?><span class="muted">Browser requires HTTPS</span><?php endif; ?><button type="button" class="btn listen-extension" data-channel="<?= e($channel['name']) ?>" data-extension="<?= e($channel['listen_extension']) ?>">Extension</button></div></td></tr>
        <?php endforeach; ?>
        <?php if (!$channels): ?><tr id="listen-calls-empty"><td colspan="4">No connected tenant extension calls found.</td></tr><?php endif; ?>
        </tbody></table></div>
</div></div>

<input type="hidden" id="listen-context" value="<?= e($context) ?>">
<input type="hidden" id="listen-browser-ready" value="<?= $browserReady ? '1' : '0' ?>">
<script type="module" src="<?= e(asset('assets/js/live-listen.js')) ?>"></script>
