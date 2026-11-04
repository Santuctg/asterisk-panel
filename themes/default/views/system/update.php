<?php
$currentVersion = (string) ($status['current_version'] ?? config('app.version', 'unknown'));
$latestVersion = (string) ($status['latest_version'] ?? 'unavailable');
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
    <div class="update-job"><div class="job-head"><div><h3>Last update job</h3><small><?= $hasUpdate ? 'A new release is ready' : 'No update required' ?></small></div><span class="job-status <?= $hasUpdate ? 'job-ready' : '' ?>"><?= $hasUpdate ? 'READY' : 'COMPLETED' ?></span></div><div class="job-steps"><span class="done"><i>1</i>Check</span><span class="done"><i>2</i>Verify</span><span class="pending"><i>3</i>Install</span><span class="pending"><i>✓</i>Complete</span></div><div class="job-version"><span>Release</span><strong>v<?= e(ltrim($latestVersion, 'v')) ?></strong></div><?php if ($hasUpdate): ?><form method="post" action="/system/update" class="job-action"><?= csrf() ?><button class="btn btn-primary" data-confirm-btn="Create a backup and install this release?">Update now</button></form><?php else: ?><div class="job-success">✓ Server is already running the latest release.</div><?php endif; ?></div>
</section>
