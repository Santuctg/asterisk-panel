<?php
/**
 * Dashboard: system health, per-feature counts, live channels, recent calls.
 * Expects: $tid, $counts, $online, $channels, $astVersion, $activeChannels,
 *          $registered, $recentCalls
 */
require dirname(__DIR__) . '/partials/fields.php';

$tile = function (string $icon, string $tone, int|string $value, string $label, string $href = '') {
    ob_start();
    ?>
    <a href="<?= e($href) ?>" class="stat" style="text-decoration:none;color:inherit">
        <div class="stat-icon <?= e($tone) ?>">
            <?= \App\Core\View::fragment('icons/' . $icon) ?>
        </div>
        <div class="stat-body">
            <div class="stat-value"><?= e((string) $value) ?></div>
            <div class="stat-label"><?= e($label) ?></div>
        </div>
    </a>
    <?php
    return ob_get_clean();
};
?>

<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <div class="subtitle">System overview and live status</div>
    </div>
    <div class="page-actions">
        <?php if (auth()->can('call.listen')): ?>
            <a href="/live-listen" class="btn">Live Listen</a>
        <?php endif; ?>
        <a href="/extensions/create" class="btn btn-primary">
            <?= $this->fragment('icons/plus') ?> New extension
        </a>
        <a href="/system/status" class="btn">
            <?= $this->fragment('icons/pulse') ?> System status
        </a>
    </div>
</div>

<?php if (!$online): ?>
    <div class="flash flash-warning" style="margin-bottom:18px">
        <span class="flash-icon"><?= $this->fragment('icons/alert') ?></span>
        <span class="flash-msg">
            <strong>Asterisk is not reachable.</strong>
            The panel is running, but it cannot connect to the AMI socket.
            Check the host/port/credentials in <a href="/system/settings">Settings</a>,
            then review <code>docs/asterisk-setup.md</code>.
        </span>
    </div>
<?php endif; ?>

<!-- Stat tiles -->
<div class="grid grid-4 mb-4">
    <?= $tile('phone',    '',       $counts['extensions'],  'Extensions',   '/extensions') ?>
    <?= $tile('device',   'violet', $counts['devices'],     'Devices',      '/devices') ?>
    <?= $tile('pulse',    $online ? 'green' : 'red',
              $online ? $activeChannels : '—',
              $online ? 'Active calls' : 'Offline',
              '/system/status') ?>
    <?= $tile('users',    'green',  $registered, 'Registered phones', '/devices') ?>
</div>

<div class="grid grid-4 mb-4">
    <?= $tile('users',   '',       $counts['ring_groups'], 'Ring groups',  '/ring-groups') ?>
    <?= $tile('menu',    'amber',  $counts['ivrs'],        'IVR menus',    '/ivr') ?>
    <?= $tile('headset', 'violet', $counts['queues'],      'Call queues',  '/call-center') ?>
    <?= $tile('conf',    '',       $counts['conferences'], 'Conferences',  '/conferences') ?>
</div>

<div class="grid grid-2">
    <!-- Live channels -->
    <div class="card">
        <div class="card-head">
            <h2>Active calls</h2>
            <span class="badge <?= $online ? 'green' : 'gray' ?>">
                <span class="dot"></span><?= $online ? 'Live' : 'Offline' ?>
            </span>
        </div>
        <div class="card-body tight">
            <?php if (!$online): ?>
                <?= \App\Core\View::fragment('partials/empty', [
                    'icon' => 'pulse', 'title' => 'No connection',
                    'message' => 'Connect to Asterisk to see live calls.',
                ]) ?>
            <?php elseif (empty($channels)): ?>
                <?= \App\Core\View::fragment('partials/empty', [
                    'icon' => 'phone', 'title' => 'No active calls',
                    'message' => 'Calls in progress will appear here in real time.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Channel</th>
                                <th>Caller</th>
                                <th>App</th>
                                <th class="num">Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($channels as $c): ?>
                                <tr>
                                    <td class="mono text-xs"><?= e($c['name']) ?></td>
                                    <td><?= e($c['caller']) ?></td>
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

    <!-- Recent calls -->
    <div class="card">
        <div class="card-head">
            <h2>Recent calls</h2>
            <a href="/cdr" class="btn btn-sm btn-ghost">View all</a>
        </div>
        <div class="card-body tight">
            <?php if (empty($recentCalls)): ?>
                <?= \App\Core\View::fragment('partials/empty', [
                    'icon' => 'clock', 'title' => 'No call history yet',
                    'message' => 'Once calls are made and CDR collection is configured, they will show up here.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Result</th>
                                <th class="num">Dur</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentCalls as $c): ?>
                                <?php
                                $tone = match ($c['disposition']) {
                                    'ANSWERED'   => 'green',
                                    'BUSY'       => 'amber',
                                    'NO ANSWER'  => 'gray',
                                    default      => 'red',
                                };
                                ?>
                                <tr>
                                    <td class="nowrap muted text-xs"><?= e(format_date($c['calldate'], 'M j, g:i A')) ?></td>
                                    <td class="mono"><?= e($c['src']) ?></td>
                                    <td class="mono"><?= e($c['dst']) ?></td>
                                    <td><span class="badge <?= $tone ?>"><?= e($c['disposition']) ?></span></td>
                                    <td class="num mono"><?= e(format_duration((int) $c['billsec'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick links -->
<div class="card mt-4">
    <div class="card-head"><h2>Quick actions</h2></div>
    <div class="card-body">
        <div class="flex gap-2" style="flex-wrap:wrap">
            <a class="btn" href="/extensions/create"><?= $this->fragment('icons/plus') ?> Add extension</a>
            <a class="btn" href="/devices/create"><?= $this->fragment('icons/device') ?> Add device</a>
            <a class="btn" href="/voicemail/create"><?= $this->fragment('icons/mail') ?> Add voicemail box</a>
            <a class="btn" href="/ring-groups/create"><?= $this->fragment('icons/users') ?> New ring group</a>
            <a class="btn" href="/ivr/create"><?= $this->fragment('icons/menu') ?> New IVR menu</a>
            <a class="btn" href="/conferences"><?= $this->fragment('icons/conf') ?> Conference rooms</a>
            <a class="btn" href="/system/apply" data-confirm-btn="Regenerate all Asterisk config and reload the daemon?">
                <?= $this->fragment('icons/refresh') ?> Apply config to Asterisk
            </a>
        </div>
    </div>
</div>
