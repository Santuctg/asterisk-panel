<?php
/**
 * System status page. Expects: $online, $version, $uptime, $channels,
 * $contacts, $conferences, $queues, $configDir, $extIps, $serverStats
 */
require dirname(__DIR__) . '/partials/fields.php';

// Older callers (cached renders, tests) may not pass the resource sample.
$serverStats = $serverStats ?? ['cpu' => null, 'memory' => null, 'disk' => null, 'network' => null];
$serverStats = array_merge(['cpu' => null, 'memory' => null, 'disk' => null, 'network' => null], $serverStats);
$serverSeries = is_array($serverSeries ?? null) ? $serverSeries : [];
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
    <div class="stat">
        <div class="stat-icon teal"><?= $this->fragment('icons/server') ?></div>
        <div class="stat-body">
            <div class="stat-value"><?= $online ? count($extIps ?? []) . ' extension(s) connected' : '—' ?></div>
            <div class="stat-label">Connected extensions</div>
        </div>
    </div>
</div>

<?php
$cpu  = $serverStats['cpu'];
$mem  = $serverStats['memory'];
$disk = $serverStats['disk'];
$net  = $serverStats['network'];

/** Clamp a percentage to a usable meter width. */
$meterWidth = static function (float $percent): int {
    return (int) round(max(0.0, min(100.0, $percent)));
};
?>
<div class="card mb-4">
    <div class="card-head">
        <h2>Server resources</h2>
        <span class="card-subtitle" id="srvSampled">Panel host · sampled live</span>
    </div>
    <div class="card-body">
        <div class="grid grid-4">
            <div class="stat">
                <div class="stat-icon"><?= $this->fragment('icons/cpu') ?></div>
                <div class="stat-body stat-fill">
                    <div class="stat-value" id="srvCpuValue"><?= $cpu !== null ? e(number_format($cpu, 1)) . '%' : '—' ?></div>
                    <div class="stat-label" id="srvCpuLabel"><?= $cpu !== null ? 'CPU usage' : 'CPU not available' ?></div>
                    <div class="meter" id="srvCpuMeterWrap"<?= $cpu === null ? ' hidden' : '' ?>>
                        <span id="srvCpuBar" class="<?= $cpu !== null ? e(usage_level((float) $cpu)) : 'ok' ?>" style="width:<?= $cpu !== null ? $meterWidth((float) $cpu) : 0 ?>%"></span>
                    </div>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon violet"><?= $this->fragment('icons/memory') ?></div>
                <div class="stat-body stat-fill">
                    <div class="stat-value" id="srvMemValue"><?= $mem !== null ? e(format_bytes((int) $mem['used'])) : '—' ?></div>
                    <div class="stat-label" id="srvMemLabel">
                        <?= $mem !== null
                            ? 'Memory · ' . e(number_format((float) $mem['percent'], 1)) . '% of ' . e(format_bytes((int) $mem['total']))
                            : 'Memory not available' ?>
                    </div>
                    <div class="meter" id="srvMemMeterWrap"<?= $mem === null ? ' hidden' : '' ?>>
                        <span id="srvMemBar" class="<?= $mem !== null ? e(usage_level((float) $mem['percent'])) : 'ok' ?>" style="width:<?= $mem !== null ? $meterWidth((float) $mem['percent']) : 0 ?>%"></span>
                    </div>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon amber"><?= $this->fragment('icons/disk') ?></div>
                <div class="stat-body stat-fill">
                    <div class="stat-value" id="srvDiskValue"><?= $disk !== null ? e(format_bytes((int) $disk['used'])) : '—' ?></div>
                    <div class="stat-label" id="srvDiskLabel">
                        <?= $disk !== null
                            ? 'Disk · ' . e(number_format((float) $disk['percent'], 1)) . '% of ' . e(format_bytes((int) $disk['total']))
                            : 'Disk not available' ?>
                    </div>
                    <div class="meter" id="srvDiskMeterWrap"<?= $disk === null ? ' hidden' : '' ?>>
                        <span id="srvDiskBar" class="<?= $disk !== null ? e(usage_level((float) $disk['percent'])) : 'ok' ?>" style="width:<?= $disk !== null ? $meterWidth((float) $disk['percent']) : 0 ?>%"></span>
                    </div>
                </div>
            </div>

            <div class="stat">
                <div class="stat-icon green"><?= $this->fragment('icons/network') ?></div>
                <div class="stat-body stat-fill">
                    <div class="stat-value" id="srvNetValue" style="font-size:18px"><?= $net !== null ? e(format_rate((float) $net['rx'])) : '—' ?></div>
                    <div class="stat-label" id="srvNetLabel"><?= $net !== null ? 'Receiving' : 'Network not available' ?></div>
                    <div class="text-xs muted" id="srvNetSub"<?= $net === null ? ' hidden' : '' ?>>Sending <?= $net !== null ? e(format_rate((float) $net['tx'])) : '0 B/s' ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php $chart = server_resource_chart($serverSeries); ?>
<div class="card mb-4">
    <div class="card-head">
        <h2>Resource history</h2>
        <span class="card-subtitle">Last 24 hours · one sample a minute</span>
    </div>
    <div class="card-body">
        <div class="chart-legend">
            <span><i style="background:#3b66f5"></i>CPU</span>
            <span><i style="background:#7c5cff"></i>Memory</span>
            <span><i style="background:#d97706"></i>Disk</span>
        </div>
        <div class="chart-host" id="srvChart">
            <?php if ($chart !== ''): ?>
                <?= $chart ?>
            <?php else: ?>
                <p class="muted mb-0">Not enough samples yet — the first point appears within a minute.</p>
            <?php endif; ?>
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

<script>
(function () {
    'use strict';

    var ENDPOINT = '/system/server-stats';
    var INTERVAL = 10000;
    var timer = null;
    var busy = false;

    function byId(id) { return document.getElementById(id); }

    function setText(id, text) {
        var el = byId(id);
        if (el) { el.textContent = text; }
    }

    // Mirrors format_bytes() in app/Support/helpers.php.
    function fmtBytes(value) {
        var bytes = Number(value);
        if (!isFinite(bytes) || bytes <= 0) { return '0 B'; }
        var units = ['B', 'kB', 'MB', 'GB', 'TB', 'PB'];
        var index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        var scaled = bytes / Math.pow(1024, index);
        if (index === 0) { return String(Math.round(scaled)) + ' B'; }
        var text = scaled.toFixed(1);
        if (text.charAt(text.length - 1) === '0') { text = text.slice(0, -1); }
        if (text.charAt(text.length - 1) === '.') { text = text.slice(0, -1); }
        return text + ' ' + units[index];
    }

    function fmtRate(value) { return fmtBytes(value) + '/s'; }

    function level(percent) { return percent >= 85 ? 'hot' : (percent >= 70 ? 'warn' : 'ok'); }

    function setMeter(wrapId, barId, percent) {
        var wrap = byId(wrapId);
        var bar = byId(barId);
        if (!wrap || !bar) { return; }
        if (percent === null || percent === undefined || !isFinite(Number(percent))) {
            wrap.hidden = true;
            return;
        }
        percent = Number(percent);
        wrap.hidden = false;
        bar.className = level(percent);
        bar.style.width = Math.max(0, Math.min(100, Math.round(percent))) + '%';
    }

    function updateTiles(current) {
        var cpu = current && typeof current.cpu === 'number' ? current.cpu : null;
        setText('srvCpuValue', cpu === null ? '—' : cpu.toFixed(1) + '%');
        setText('srvCpuLabel', cpu === null ? 'CPU not available' : 'CPU usage');
        setMeter('srvCpuMeterWrap', 'srvCpuBar', cpu);

        var mem = current && current.memory;
        setText('srvMemValue', mem ? fmtBytes(mem.used) : '—');
        setText('srvMemLabel', mem
            ? 'Memory · ' + Number(mem.percent).toFixed(1) + '% of ' + fmtBytes(mem.total)
            : 'Memory not available');
        setMeter('srvMemMeterWrap', 'srvMemBar', mem ? mem.percent : null);

        var disk = current && current.disk;
        setText('srvDiskValue', disk ? fmtBytes(disk.used) : '—');
        setText('srvDiskLabel', disk
            ? 'Disk · ' + Number(disk.percent).toFixed(1) + '% of ' + fmtBytes(disk.total)
            : 'Disk not available');
        setMeter('srvDiskMeterWrap', 'srvDiskBar', disk ? disk.percent : null);

        var net = current && current.network;
        setText('srvNetValue', net ? fmtRate(net.rx) : '—');
        setText('srvNetLabel', net ? 'Receiving' : 'Network not available');
        setText('srvNetSub', net ? 'Sending ' + fmtRate(net.tx) : 'Sending 0 B/s');
        var sub = byId('srvNetSub');
        if (sub) { sub.hidden = !net; }
    }

    // Same geometry as server_resource_chart() so the first poll matches the
    // server-rendered SVG exactly.
    function renderChart(series) {
        var host = byId('srvChart');
        if (!host) { return; }

        var points = (series || []).filter(function (p) {
            return p && typeof p.t === 'number';
        });

        if (points.length < 2) {
            host.innerHTML = '<p class="muted mb-0">Not enough samples yet — the first point appears within a minute.</p>';
            return;
        }

        var W = 900, H = 250, padL = 48, padR = 14, padT = 16, padB = 30;
        var x0 = padL, x1 = W - padR, y0 = padT, y1 = H - padB;
        var t0 = points[0].t, t1 = points[points.length - 1].t;
        if (t1 <= t0) { t1 = t0 + 1; }
        var span = t1 - t0;

        var px = function (t) { return x0 + (t - t0) / span * (x1 - x0); };
        var py = function (p) {
            p = Math.max(0, Math.min(100, Number(p)));
            return y1 - p / 100 * (y1 - y0);
        };
        var r1 = function (n) { return Math.round(n * 10) / 10; };

        var parts = [];

        [0, 25, 50, 75, 100].forEach(function (pct) {
            var y = r1(py(pct));
            parts.push('<line x1="' + x0 + '" y1="' + y + '" x2="' + x1 + '" y2="' + y + '" stroke="currentColor" stroke-opacity=".16" stroke-width="1"/>');
            parts.push('<text x="' + (x0 - 9) + '" y="' + r1(y + 3.5) + '" text-anchor="end" class="chart-axis">' + pct + '%</text>');
        });

        [0, 0.25, 0.5, 0.75, 1].forEach(function (fraction) {
            var x = r1(px(t0 + Math.round(fraction * span)));
            var ago = span * (1 - fraction);
            var label = fraction >= 1
                ? 'now'
                : (ago >= 3600 ? '-' + Math.round(ago / 3600) + 'h' : '-' + Math.round(ago / 60) + 'm');
            var anchor = fraction <= 0 ? 'start' : (fraction >= 1 ? 'end' : 'middle');
            parts.push('<text x="' + x + '" y="' + (y1 + 19) + '" text-anchor="' + anchor + '" class="chart-axis">' + label + '</text>');
        });

        [['cpu', '#3b66f5'], ['mem', '#7c5cff'], ['disk', '#d97706']].forEach(function (line) {
            var d = '';
            var open = false;
            var plotted = 0;
            points.forEach(function (p) {
                var v = p[line[0]];
                if (typeof v !== 'number' || !isFinite(v)) { open = false; return; }
                d += (open ? 'L' : 'M') + r1(px(p.t)) + ' ' + r1(py(v)) + ' ';
                open = true;
                plotted++;
            });
            d = d.replace(/\s+$/, '');
            if (plotted < 2 || !d) { return; }
            parts.push('<path d="' + d + '" fill="none" stroke="' + line[1] + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>');
        });

        host.innerHTML = '<svg class="chart" viewBox="0 0 ' + W + ' ' + H
            + '" role="img" aria-label="CPU, memory and disk usage over the last 24 hours">'
            + parts.join('') + '</svg>';
    }

    function poll() {
        if (busy || document.hidden) { return; }
        busy = true;
        fetch(ENDPOINT, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
            .then(function (res) {
                if (!res.ok) { throw new Error('HTTP ' + res.status); }
                return res.json();
            })
            .then(function (data) {
                if (!data) { return; }
                if (data.current) { updateTiles(data.current); }
                if (data.series) { renderChart(data.series); }
                setText('srvSampled', 'Panel host · updated ' + new Date().toLocaleTimeString());
            })
            .catch(function () { /* keep the last known values */ })
            .then(function () { busy = false; });
    }

    function start() {
        if (timer) { return; }
        timer = setInterval(poll, INTERVAL);
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            clearInterval(timer);
            timer = null;
        } else {
            poll();
            start();
        }
    });

    start();
})();
</script>
