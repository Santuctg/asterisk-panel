<div class="page-head"><div>
    <h1>Active Calls</h1>
    <div class="subtitle">Grandstream-style real-time call monitoring for the selected tenant.</div>
</div><div class="page-actions"><button type="button" class="btn" id="listen-refresh">Refresh calls</button></div></div>

<div class="active-calls-hero mb-4">
    <div><span class="active-calls-live"><i></i> Live monitoring</span><h2>Calls in progress</h2><p>Calls update automatically. Listen in this browser or through another tenant extension.</p></div>
    <div class="active-calls-total"><strong id="active-calls-total"><?= count($channels) ?></strong><span>Active sessions</span></div>
</div>

<div class="active-calls-listen-bar mb-4"><div><strong>Listen-only monitoring</strong><small>Browser Listen connects directly with a restricted panel identity. Whisper and barge are disabled.</small></div><div><span id="listen-refresh-status" class="muted text-sm" role="status" aria-live="polite">Calls update automatically.</span><button id="listen-stop" type="button" class="btn btn-sm" hidden>Stop listening</button></div></div>
<p id="listen-status" class="active-calls-message" role="status" aria-live="polite"></p>
<audio id="listen-audio" class="mb-4" autoplay playsinline controls hidden></audio>

<div id="listen-extension-dialog" class="card mb-4" hidden><div class="card-body">
    <h3>Listen on an extension</h3>
    <p class="muted">Monitor extension <strong id="listen-target-label"></strong>. The selected listener phone will ring first.</p>
    <div class="form-grid cols-2"><div class="field"><label for="listen-extension">Listener extension</label><select id="listen-extension">
        <option value="">Select extension</option><?php foreach ($extensions as $extension): ?><option value="<?= e($extension['extension']) ?>"><?= e($extension['extension'] . ' · ' . ($extension['name'] ?: $extension['extension'])) ?></option><?php endforeach; ?>
    </select></div><div class="form-actions"><button type="button" class="btn btn-primary" id="listen-extension-start">Ring extension</button><button type="button" class="btn" id="listen-extension-cancel">Cancel</button></div></div>
</div></div>

<div class="active-calls-grid" id="listen-calls-body" aria-live="polite">
    <?php foreach ($channels as $channel): $canListen = !empty($channel['monitorable']); ?>
        <article class="active-session-card">
            <div class="active-session-head"><span class="active-session-icon">☎</span><div><strong>Extension <?= e($channel['listen_extension']) ?></strong><small><?= e($channel['device']) ?> · <?= e($channel['direction']) ?></small></div><span class="active-session-state <?= $channel['status'] === 'Connected' ? 'is-connected' : '' ?>"><?= e($channel['status']) ?></span></div>
            <div class="active-session-route"><div><small><?= $channel['direction'] === 'Inbound' ? 'From' : 'Extension' ?></small><strong><?= e($channel['direction'] === 'Inbound' ? $channel['remote'] : $channel['listen_extension']) ?></strong></div><span>→</span><div><small><?= $channel['direction'] === 'Inbound' ? 'Extension' : 'To' ?></small><strong><?= e($channel['direction'] === 'Inbound' ? $channel['listen_extension'] : $channel['remote']) ?></strong></div></div>
            <div class="active-session-meta"><span>Duration <strong class="mono"><?= e($channel['duration']) ?></strong></span><span><?= e($channel['device']) ?></span></div>
            <div class="active-session-actions"><?php if ($browserReady): ?><button type="button" class="btn btn-primary listen-browser" data-channel="<?= e($channel['name']) ?>" data-extension="<?= e($channel['listen_extension']) ?>"<?= $canListen ? '' : ' disabled' ?>>Listen in browser</button><?php endif; ?><button type="button" class="btn listen-extension" data-channel="<?= e($channel['name']) ?>" data-extension="<?= e($channel['listen_extension']) ?>"<?= $canListen ? '' : ' disabled' ?>>Listen on extension</button><button type="button" class="btn btn-danger active-call-hangup" data-channel="<?= e($channel['name']) ?>" data-extension="<?= e($channel['listen_extension']) ?>">End call</button></div>
        </article>
    <?php endforeach; ?>
    <?php if (!$channels): ?><div class="empty dashboard-empty active-calls-empty"><span class="active-call-empty-icon">☎</span><strong>No active calls</strong><p>Tenant calls will appear here automatically.</p></div><?php endif; ?>
</div>

<input type="hidden" id="listen-browser-ready" value="<?= $browserReady ? '1' : '0' ?>">
<script type="module" src="<?= e(asset('assets/js/live-listen.js')) ?>"></script>
