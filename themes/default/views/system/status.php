<?php
/**
 * System status page. Expects: $online, $version, $uptime, $channels,
 * $contacts, $conferences, $queues, $configDir
 */
require dirname(__DIR__) . '/partials/fields.php';
?>

<div class="page-head">
    <div>
        <h1>System Status</h1>
        <div class="subtitle">Live Asterisk state and configuration deployment</div>
    </div>
    <div class="page-actions">
        <form method="post" action="/system/apply" class="inline">
            <?= csrf() ?>
            <input type="hidden" name="feature" value="all">
            <button class="btn btn-primary" data-confirm-btn="Regenerate ALL Asterisk config files and reload the daemon?">
                <?= $this->fragment('icons/refresh') ?> Apply all config
            </button>
        </form>
        <a href="/system/settings" class="btn"><?= $this->fragment('icons/cog') ?> Settings</a>
    </div>
</div>

<div class="grid grid-4 mb-4">
    <div class="stat">
        <div class="stat-icon <?= $online ? 'green' : 'red' ?>"><?= $this->fragment('icons/pulse') ?></div>
        <div class="stat-body">
            <div class="stat-value"><?= $online ? 'Online' : 'Offline' ?></div>
            <div class="stat-label">Asterisk connection</div>
        </div>
    </div>
    <div class="stat">
        <div class="stat-icon"><?= $this->fragment('icons/cog') ?></div>
        <div class="stat-body">
            <div class="stat-value"><?= e($version['version'] ?? '—') ?></div>
            <div class="stat-label">Asterisk version</div>
        </div>
    </div>
    <div class="stat">
        <div class="stat-icon violet"><?= $this->fragment('icons/users') ?></div>
        <div class="stat-body">
            <div class="stat-value"><?= count($contacts) ?></div>
            <div class="stat-label">Registered endpoints</div>
        </div>
    </div>
    <div class="stat">
        <div class="stat-icon amber"><?= $this->fragment('icons/clock') ?></div>
        <div class="stat-body">
            <div class="stat-value" style="font-size:15px;line-height:1.5"><?= e(preg_replace('/\s+/', ' ', trim($uptime)) ?: '—') ?></div>
            <div class="stat-label">Uptime</div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-head"><h2>Apply configuration</h2></div>
    <div class="card-body">
        <p class="muted">
            The panel stores your settings in its own database, then renders them into Asterisk
            config files under <code class="mono"><?= e($configDir) ?></code>. Choose a feature to
            regenerate only the relevant files, or apply everything.
        </p>
        <div class="flex gap-2" style="flex-wrap:wrap">
            <?php
            $features = [
                'all'         => 'Everything',
                'extension'   => 'Extensions + dialplan',
                'voicemail'   => 'Voicemail',
                'trunk'       => 'Trunks',
                'ringgroup'   => 'Ring groups',
                'ivr'         => 'IVR menus',
                'route'       => 'Call routing',
                'timecondition' => 'Time conditions',
                'parking'     => 'Parking',
                'queue'       => 'Call queues',
                'moh'         => 'Music on hold',
                'conference'  => 'Conferences',
            ];
            foreach ($features as $key => $label): ?>
                <form method="post" action="/system/apply" class="inline">
                    <?= csrf() ?>
                    <input type="hidden" name="feature" value="<?= e($key) ?>">
                    <button class="btn btn-sm" data-confirm-btn="Regenerate <?= e(strtolower($label)) ?> config and reload Asterisk?">
                        <?= e($label) ?>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if ($online): ?>
<div class="grid grid-2">
    <div class="card">
        <div class="card-head">
            <h2>Live channels</h2>
            <span class="badge green"><span class="dot"></span>Live</span>
        </div>
        <div class="card-body tight">
            <?php if (empty($channels)): ?>
                <?= \App\Core\View::fragment('partials/empty', [
                    'icon' => 'phone', 'title' => 'No active calls', 'message' => 'Nothing is in progress right now.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Channel</th><th>Context</th><th>App</th><th class="num">Dur</th></tr></thead>
                        <tbody>
                            <?php foreach ($channels as $c): ?>
                                <tr>
                                    <td class="mono text-xs"><?= e($c['name']) ?></td>
                                    <td class="muted"><?= e($c['context']) ?></td>
                                    <td><span class="badge gray"><?= e($c['app']) ?></span></td>
                                    <td class="num mono"><?= e($c['duration']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head">
            <h2>Registered endpoints</h2>
            <span class="badge blue"><?= count($contacts) ?></span>
        </div>
        <div class="card-body tight">
            <?php if (empty($contacts)): ?>
                <?= \App\Core\View::fragment('partials/empty', [
                    'icon' => 'device', 'title' => 'No registrations', 'message' => 'No phones are currently registered.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>URI</th><th>Status</th><th>User agent</th></tr></thead>
                        <tbody>
                            <?php foreach ($contacts as $c): ?>
                                <tr>
                                    <td class="mono text-xs"><?= e($c['uri']) ?></td>
                                    <td><span class="badge green"><?= e($c['status']) ?></span></td>
                                    <td class="muted text-xs"><?= e($c['agent']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($queues)): ?>
<div class="card mt-4">
    <div class="card-head"><h2>Queue status</h2></div>
    <div class="card-body tight">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Queue</th><th>Members</th></tr></thead>
                <tbody>
                    <?php foreach ($queues as $qq): ?>
                        <tr>
                            <td><strong class="mono"><?= e($qq['name']) ?></strong></td>
                            <td>
                                <?php if (empty($qq['members'])): ?>
                                    <span class="muted">No members online</span>
                                <?php else: ?>
                                    <div class="flex gap-2" style="flex-wrap:wrap">
                                        <?php foreach ($qq['members'] as $m): ?>
                                            <span class="badge <?= $m['state'] === 'inuse' ? 'amber' : 'green' ?>">
                                                <?= e($m['member']) ?> · <?= e($m['state']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
    <div class="card">
        <div class="card-body">
            <div class="flash flash-warning mb-0">
                <span class="flash-icon"><?= $this->fragment('icons/alert') ?></span>
                <span class="flash-msg">
                    <strong>Could not reach the Asterisk Manager Interface.</strong><br>
                    Verify <code>AMI_HOST</code>, <code>AMI_PORT</code>, <code>AMI_USER</code> and
                    <code>AMI_SECRET</code> match your <code>/etc/asterisk/manager.conf</code>, and that
                    the panel host is permitted by <code>permit</code> rules. See <code>docs/asterisk-setup.md</code>.
                </span>
            </div>
        </div>
    </div>
<?php endif; ?>
