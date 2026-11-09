<?php
/**
 * Dashboard: system health, per-feature counts, live channels, recent calls.
 * Expects: $tid, $counts, $online, $channels, $astVersion, $activeChannels,
 *          $registered, $recentCalls
 */
require dirname(__DIR__) . '/partials/fields.php';

$tile = function (string $icon, string $tone, int|string $value, string $label, string $href = '', string $valueId = '') {
    ob_start();
    ?>
    <a href="<?= e($href) ?>" class="stat" style="text-decoration:none;color:inherit">
        <div class="stat-icon <?= e($tone) ?>">
            <?= \App\Core\View::fragment('icons/' . $icon) ?>
        </div>
        <div class="stat-body">
            <div class="stat-value"<?= $valueId !== '' ? ' id="' . e($valueId) . '"' : '' ?>><?= e((string) $value) ?></div>
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
            <a href="/active-calls" class="btn">Active Calls</a>
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
              auth()->can('call.listen') ? '/active-calls' : '/system/status', 'activeCallsCount') ?>
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
            <span id="activeCallsStatus" class="badge <?= $online ? 'green' : 'gray' ?>">
                <span class="dot"></span><?= $online ? 'Live' : 'Offline' ?>
            </span>
        </div>
        <div class="card-body tight dashboard-live-body" id="activeCallsBody" aria-live="polite"></div>
    </div>

    <!-- Recent calls -->
    <div class="card">
        <div class="card-head">
            <h2>Recent calls</h2>
            <a href="/cdr" class="btn btn-sm btn-ghost">View all</a>
        </div>
        <div class="card-body tight" id="recentCallsBody" aria-live="polite"></div>
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
            <?php if (auth()->can('system.apply')): ?>
                <form method="post" action="/system/apply" class="inline">
                    <?= csrf() ?>
                    <input type="hidden" name="feature" value="all">
                    <button class="btn" data-confirm-btn="Regenerate all Asterisk config and reload the daemon?">
                        <?= $this->fragment('icons/refresh') ?> Apply config to Asterisk
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    var body = document.getElementById('activeCallsBody');
    var status = document.getElementById('activeCallsStatus');
    var count = document.getElementById('activeCallsCount');
    var recentBody = document.getElementById('recentCallsBody');
    if (!body || !status || !count || !recentBody) return;

    function emptyState(target, titleText, messageText) {
        var empty = document.createElement('div');
        empty.className = 'empty dashboard-empty';
        var icon = document.createElement('span');
        icon.className = 'active-call-empty-icon';
        icon.textContent = '☎';
        var title = document.createElement('strong');
        title.textContent = titleText;
        var message = document.createElement('p');
        message.textContent = messageText;
        empty.append(icon, title, message);
        target.appendChild(empty);
    }

    function endpoint(channel) {
        var name = String((channel && channel.name) || '').replace(/^PJSIP\//, '').replace(/-[a-f\d]+$/i, '');
        return name.split('@')[0] || 'Unknown';
    }

    function endpointLabel(channel) {
        return String((channel && channel.trunk_name) || endpoint(channel));
    }
    function validNumber(value) {
        value = String(value || '');
        return value && value !== 's' && value !== '<unknown>' ? value : '';
    }
    function callNumber(channel, peer, fallback) {
        var values = [channel && channel.extension, peer && peer.connected, peer && peer.caller, fallback];
        return String(values.find(function (value) {
            return validNumber(value);
        }) || '—');
    }

    function callCards(channels) {
        var byName = {};
        var parents = channels.map(function (_, index) { return index; });
        function find(index) { if (parents[index] !== index) parents[index] = find(parents[index]); return parents[index]; }
        function union(left, right) { left = find(left); right = find(right); if (left !== right) parents[right] = left; }
        var identities = {};
        channels.forEach(function (channel, index) {
            byName[channel.name] = channel;
            ['linkedid', 'bridgeid'].forEach(function (field) {
                if (!channel[field]) return;
                var key = field + ':' + channel[field];
                if (identities[key] !== undefined) union(index, identities[key]); else identities[key] = index;
            });
        });
        channels.forEach(function (channel, index) {
            var peerIndex = channels.findIndex(function (candidate) { return candidate.name === channel.bridged; });
            if (peerIndex >= 0) union(index, peerIndex);
        });
        var groups = {};
        channels.forEach(function (channel, index) { var key = find(index); (groups[key] || (groups[key] = [])).push(channel); });
        var calls = [];
        Object.keys(groups).forEach(function (key) {
            var linked = groups[key];
            var local = linked.find(function (leg) { return String(leg.name || '').includes('@'); }) || null;
            var trunk = linked.find(function (leg) {
                return leg.trunk_name || /^PJSIP\/trunk-\d+-/i.test(String(leg.name || ''));
            }) || null;
            var inbound = linked.find(function (leg) { return String(leg.context || '').includes('-inbound'); }) || null;
            // Dialplan Gosubs can replace the original *-inbound context while
            // a call is ringing. Linkedid remains the oldest channel Uniqueid:
            // if that oldest channel is the trunk, this is definitively inbound.
            if (!inbound && trunk && trunk.uniqueid && trunk.uniqueid === trunk.linkedid) inbound = trunk;
            var channel = local || linked[0];
            var peer = channel.bridged && byName[channel.bridged] ? byName[channel.bridged]
                : linked.find(function (leg) { return leg !== channel; }) || null;
            if (inbound) {
                var answered = linked.find(function (leg) {
                    return String(leg.name || '').includes('@')
                        && String(leg.state || '').toLowerCase() === 'up'
                        && (leg.bridged || leg.bridgeid);
                }) || null;
                var stageLeg = linked.find(function (leg) { return leg.call_stage; }) || trunk || inbound;
                var stage = String((stageLeg && stageLeg.call_stage) || 'Inbound routing');
                var incomingNumber = validNumber(inbound.inbound_caller)
                    || validNumber(trunk && trunk.inbound_caller)
                    || validNumber(inbound.caller)
                    || validNumber(trunk && trunk.caller)
                    || validNumber(local && local.caller)
                    || validNumber(inbound.connected)
                    || 'Unknown';
                var targetNumber = answered ? endpoint(answered) : stage;
                calls.push({
                    from: incomingNumber,
                    fromEndpoint: endpointLabel(inbound),
                    to: answered ? 'Answered extension' : 'Current stage',
                    toEndpoint: targetNumber,
                    status: answered ? 'Connected' : (/^(IVR|Announcement|Conference|Voicemail)(?::|$)/i.test(stage) ? 'In progress' : 'Ringing'),
                    duration: String(inbound.duration || channel.duration || '00:00:00'),
                    route: stage
                });
                return;
            }
            var destination = peer ? endpointLabel(peer) : String(channel.extension || channel.data || '—').split(',')[0];
            calls.push({
                from: String(channel.caller || endpoint(channel) || '—'),
                fromEndpoint: endpointLabel(channel),
                to: destination || '—',
                toEndpoint: peer ? callNumber(channel, peer, endpoint(peer)) : destination,
                status: String(channel.state || '').toLowerCase() === 'up' ? 'Connected' : 'Ringing',
                duration: String(channel.duration || '00:00:00'),
                route: String(channel.app || 'Call')
            });
        });
        return calls;
    }

    function renderActive(data) {
        var calls = callCards(data.channels || []);
        count.textContent = data.online ? String(calls.length) : '—';
        count.closest('.stat-body').querySelector('.stat-label').textContent = data.online ? 'Active calls' : 'Offline';
        status.className = 'badge ' + (data.online ? 'green' : 'gray');
        status.replaceChildren();
        var dot = document.createElement('span');
        dot.className = 'dot';
        status.appendChild(dot);
        status.appendChild(document.createTextNode(data.online ? 'Live' : 'Offline'));

        body.replaceChildren();
        if (!data.online || !data.channels || data.channels.length === 0) {
            emptyState(body, data.online ? 'No active calls' : 'No connection', data.online ? 'Calls in progress will appear here automatically.' : 'Asterisk is currently unreachable.');
            return;
        }

        var grid = document.createElement('div');
        grid.className = 'active-call-grid';
        calls.forEach(function (call) {
            var card = document.createElement('article');
            card.className = 'active-call-card';
            var head = document.createElement('div');
            head.className = 'active-call-head';
            head.innerHTML = '<span class="active-call-state"><i></i></span><span class="mono"></span>';
            head.querySelector('.active-call-state').appendChild(document.createTextNode(call.status));
            head.querySelector('.mono').textContent = call.duration;
            var people = document.createElement('div');
            people.className = 'active-call-people';
            function person(number, label) {
                var el = document.createElement('div');
                el.className = 'active-call-person';
                var avatar = document.createElement('span'); avatar.className = 'active-call-avatar'; avatar.textContent = '●';
                var strong = document.createElement('strong'); strong.textContent = number || '—';
                var small = document.createElement('small'); small.textContent = label || number || '—';
                el.append(avatar, strong, small); return el;
            }
            var connector = document.createElement('div');
            connector.className = 'active-call-connector';
            connector.innerHTML = '<span></span><span></span><span></span><small></small>';
            connector.querySelector('small').textContent = call.route;
            people.append(person(call.from, call.fromEndpoint), connector, person(call.toEndpoint, call.to));
            card.append(head, people);
            grid.appendChild(card);
        });
        body.appendChild(grid);
    }

    function renderRecent(calls) {
        recentBody.replaceChildren();
        if (!calls || calls.length === 0) {
            emptyState(recentBody, 'No call history yet', 'Completed calls will appear here automatically.');
            return;
        }
        var wrap = document.createElement('div'); wrap.className = 'table-wrap';
        var table = document.createElement('table');
        table.innerHTML = '<thead><tr><th>Time</th><th>From</th><th>To</th><th>Result</th><th class="num">Dur</th></tr></thead><tbody></tbody>';
        var tbody = table.querySelector('tbody');
        calls.forEach(function (call) {
            var row = document.createElement('tr');
            [call.time, call.src, call.dst, call.disposition, call.duration].forEach(function (value, index) {
                var td = document.createElement('td'); td.textContent = value || '—';
                if (index === 0) td.className = 'nowrap muted text-xs';
                if (index === 1 || index === 2 || index === 4) td.className += ' mono';
                if (index === 3) { var badge = document.createElement('span'); badge.className = 'badge ' + (value === 'ANSWERED' ? 'green' : (value === 'BUSY' ? 'amber' : (value === 'NO ANSWER' ? 'gray' : 'red'))); badge.textContent = value; td.replaceChildren(badge); }
                if (index === 4) td.className += ' num';
                row.appendChild(td);
            });
            tbody.appendChild(row);
        });
        wrap.appendChild(table); recentBody.appendChild(wrap);
    }

    function render(data) {
        renderActive(data);
        renderRecent(data.recent_calls || []);
    }

    async function poll() {
        try {
            var response = await fetch('/dashboard/live-calls', {
                headers: { 'Accept': 'application/json' }, cache: 'no-store'
            });
            if (response.ok) render(await response.json());
        } catch (error) {
            // Keep the last good snapshot during a temporary network failure.
        } finally {
            window.setTimeout(poll, document.hidden ? 10000 : 3000);
        }
    }
    render(<?= json_encode([
        'online' => $online,
        'count' => \App\Controllers\DashboardController::activeCallCount($channels),
        'channels' => array_values($channels),
        'recent_calls' => array_map(static fn (array $call): array => [
            'time' => format_cdr_date($call['calldate'], 'M j, g:i A'),
            'src' => (string) $call['src'], 'dst' => (string) $call['dst'],
            'disposition' => (string) $call['disposition'],
            'duration' => format_duration((int) $call['billsec']),
        ], $recentCalls),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
    poll();
})();
</script>
