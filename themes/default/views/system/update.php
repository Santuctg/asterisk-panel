<?php
$currentVersion = (string) ($status['current_version'] ?? config('app.version', 'unknown'));
$latestVersion = (string) ($status['latest_version'] ?? 'unavailable');
$jobState = (string) ($updateJob['state'] ?? 'idle');
$jobActive = in_array($jobState, ['queued', 'running'], true);
?>
<div class="page-head update-page-head"><div><h1>Main Updates</h1><div class="subtitle">Install verified releases from the official GitHub repository</div></div></div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<section class="update-shell">
    <div class="update-hero"><div><div class="eyebrow"><span class="status-dot"></span> Official release channel</div><h2>Keep your main server current</h2><p>Check the official GitHub Releases feed and install only validated fast-forward updates.</p></div><form method="get" action="/system/update"><button class="btn update-check">↻&nbsp; Check again</button></form></div>
    <div class="update-card-grid">
        <div class="update-stat"><div class="update-stat-icon">▤</div><div><span>Installed version</span><strong>v<?= e(ltrim($currentVersion, 'v')) ?></strong><small>Currently running on this server</small></div></div>
        <div class="update-stat"><div class="update-stat-icon">↓</div><div><span>Latest release</span><strong>v<?= e(ltrim($latestVersion, 'v')) ?></strong><small>Published on GitHub</small></div></div>
        <div class="update-stat <?= $hasUpdate ? 'update-needed' : 'update-ok' ?>"><div class="update-stat-icon">✓</div><div><span>Update status</span><strong><?= $hasUpdate ? 'Update available' : 'Up to date' ?></strong><small><?= $hasUpdate ? 'A newer release is ready to install' : 'You are running the latest release' ?></small></div></div>
    </div>
    <div class="release-row"><div class="github-mark">GH</div><div class="release-info"><span>Verified GitHub release</span><strong>Version v<?= e(ltrim($latestVersion, 'v')) ?></strong><small>Official repository release</small></div><a class="release-link" href="<?= e($releaseUrl) ?>" target="_blank" rel="noopener">View release notes ↗</a><span class="release-badge"><?= $hasUpdate ? 'Update available' : 'Latest installed' ?></span></div>
    <div class="verification-grid"><div><b>✓</b><strong>Release verified</strong><small>Fast-forward update only.</small></div><div><b>▣</b><strong>Backup created</strong><small>Backup runs before installation.</small></div><div><b>⚙</b><strong>Privileged isolation</strong><small>Runs through the root-owned helper.</small></div></div>
    <div class="update-job" id="updateJob" data-state="<?= e($jobState) ?>"><div class="job-head"><div><h3>Update progress</h3><small id="updateJobMessage"><?= e((string) ($updateJob['message'] ?? ($hasUpdate ? 'A new release is ready' : 'No update required'))) ?></small></div><span class="job-status <?= $jobActive ? 'job-running' : ($hasUpdate ? 'job-ready' : '') ?>" id="updateJobStatus"><?= e($jobActive ? 'RUNNING' : ($jobState === 'failed' ? 'FAILED' : ($hasUpdate ? 'READY' : 'COMPLETED'))) ?></span></div><div class="job-steps" id="updateJobSteps"><span class="done" data-step="check"><i>1</i>Check</span><span class="done" data-step="verify"><i>2</i>Verify</span><span class="<?= $jobActive ? 'running' : 'pending' ?>" data-step="install"><i>3</i>Install</span><span class="<?= $jobState === 'completed' ? 'done' : 'pending' ?>" data-step="complete"><i>✓</i>Complete</span></div><div class="job-version"><span>Release</span><strong>v<?= e(ltrim((string) ($updateJob['target_version'] ?? $latestVersion), 'v')) ?></strong></div><?php if ($hasUpdate || $jobActive): ?><form method="post" action="/system/update" class="job-action" id="updateForm"<?= $jobActive ? ' hidden' : '' ?>><?= csrf() ?><button class="btn btn-primary" data-confirm-btn="Create a backup and install this release?">Update now</button></form><?php else: ?><div class="job-success" id="updateSuccess">✓ Server is already running the latest release.</div><?php endif; ?></div>
</section>
<script>
(function () {
    var job = document.getElementById('updateJob');
    var form = document.getElementById('updateForm');
    var status = document.getElementById('updateJobStatus');
    var message = document.getElementById('updateJobMessage');
    var timer = null;
    if (!job || !status || !message) return;

    function paint(data) {
        var state = data.state || 'idle';
        job.dataset.state = state;
        status.textContent = state === 'completed' ? 'COMPLETED' : (state === 'failed' ? 'FAILED' : (state === 'queued' || state === 'running' ? 'RUNNING' : 'READY'));
        status.className = 'job-status ' + (state === 'queued' || state === 'running' ? 'job-running' : (state === 'failed' ? 'job-failed' : ''));
        message.textContent = data.message || (state === 'running' ? 'Installing release…' : 'Ready');
        var install = job.querySelector('[data-step="install"]');
        var complete = job.querySelector('[data-step="complete"]');
        install.className = state === 'completed' ? 'done' : (state === 'queued' || state === 'running' ? 'running' : (state === 'failed' ? 'failed' : 'pending'));
        complete.className = state === 'completed' ? 'done' : (state === 'failed' ? 'failed' : 'pending');
        if (form) form.hidden = state === 'queued' || state === 'running' || state === 'completed';
        return state;
    }

    async function poll() {
        try {
            var response = await fetch('/system/update/progress', {headers:{'Accept':'application/json'}, cache:'no-store'});
            if (!response.ok) throw new Error('unavailable');
            var state = paint(await response.json());
            if (state === 'completed') { message.textContent = 'Update completed. Reloading the current version…'; window.setTimeout(function () { location.reload(); }, 1200); return; }
            if (state === 'failed') return;
        } catch (error) {
            status.textContent = 'RECONNECTING'; status.className = 'job-status job-running';
            message.textContent = 'The server is restarting. This page will reconnect automatically…';
        }
        timer = window.setTimeout(poll, 1000);
    }

    if (form) form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (form.dataset.submitting === '1') return;
        form.dataset.submitting = '1'; form.hidden = true;
        paint({state:'queued', message:'Starting update…'});
        try {
            var response = await fetch(form.action, {method:'POST', body:new FormData(form), headers:{'Accept':'application/json'}});
            var data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Unable to start update.');
            paint(data); poll();
        } catch (error) {
            paint({state:'failed', message:error.message}); form.hidden = false; form.dataset.submitting = '0';
        }
    });
    if (job.dataset.state === 'queued' || job.dataset.state === 'running') poll();
})();
</script>
