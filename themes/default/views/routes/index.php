<?php
/**
 * Call routing hub: inbound routes, outbound routes, trunks, time conditions.
 * Expects: $inbound, $outbound, $trunks, $times, $destinations, $destTypes
 */
require dirname(__DIR__) . '/partials/fields.php';

$typeLabels = [
    'extension'  => 'Extension',
    'ringgroup'  => 'Ring group',
    'ivr'        => 'IVR menu',
    'queue'      => 'Call queue',
    'conference' => 'Conference',
    'parking'    => 'Parking lot',
    'timecond'   => 'Time condition',
    'announcement' => 'Announcement',
    'external'   => 'External number',
    'hangup'     => 'Hang up',
];
$activeTab = $_GET['tab'] ?? 'inbound';
?>

<div class="page-head">
    <div>
        <h1>Call Routing</h1>
        <div class="subtitle">Where calls come in, where they go out, and the trunks in between</div>
    </div>
</div>

<div class="card">
    <div class="tabs">
        <a href="?tab=inbound"  class="tab<?= $activeTab === 'inbound'  ? ' is-active' : '' ?>">Inbound routes</a>
        <a href="?tab=outbound" class="tab<?= $activeTab === 'outbound' ? ' is-active' : '' ?>">Outbound routes</a>
        <a href="?tab=trunks"   class="tab<?= $activeTab === 'trunks'   ? ' is-active' : '' ?>">Trunks</a>
        <a href="?tab=time"     class="tab<?= $activeTab === 'time'     ? ' is-active' : '' ?>">Time conditions</a>
    </div>

    <!-- ======================= INBOUND ======================= -->
    <?php if ($activeTab === 'inbound'): ?>
        <div class="card-head">
            <h2>Inbound routes</h2>
            <button class="btn btn-primary btn-sm" data-modal-open="inboundModal">
                <?= $this->fragment('icons/plus') ?> Add inbound route
            </button>
        </div>
        <div class="card-body tight">
            <?php if (empty($inbound)): ?>
                <?= \App\Core\View::fragment('partials/empty', [
                    'icon' => 'route', 'title' => 'No inbound routes',
                    'message' => 'Route incoming DIDs from your trunk to an extension, IVR, ring group, or queue.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Name</th><th>DID pattern</th><th>Destination</th><th>Announcement</th><th class="num">Priority</th><th>Status</th><th class="actions">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($inbound as $r): ?>
                                <tr>
                                    <td><strong><?= e($r['name']) ?></strong></td>
                                    <td><code class="mono"><?= e($r['did_pattern']) ?></code></td>
                                    <td><span class="badge blue"><?= e($typeLabels[$r['dest_type']] ?? $r['dest_type']) ?></span> <?= e($r['dest_label']) ?></td>
                                    <td><?= !empty($r['announcement_name']) ? e($r['announcement_name']) : '<span class="muted">—</span>' ?></td>
                                    <td class="num"><?= (int) $r['priority'] ?></td>
                                    <td><?= $r['enabled'] ? '<span class="badge green">On</span>' : '<span class="badge gray">Off</span>' ?></td>
                                    <td class="actions">
                                        <button class="btn btn-sm" data-modal-open="inboundModal"
                                                data-edit='<?= e(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                                        <form method="post" action="/routes/inbound/<?= (int) $r['id'] ?>">
                                            <?= csrf() ?><input type="hidden" name="_method" value="DELETE">
                                            <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                    data-confirm-btn="Delete inbound route <?= e($r['name']) ?>?">
                                                <?= $this->fragment('icons/trash') ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="modal-overlay" id="inboundModal" hidden>
            <div class="modal">
                <form method="post" action="/routes/inbound" id="inboundForm">
                    <?= csrf() ?>
                    <div class="modal-head">
                        <h2 id="inboundTitle">Add inbound route</h2>
                        <button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button>
                    </div>
                    <div class="modal-body">
                        <div class="form-grid">
                            <?php echo field_text('name', 'Route name', '', ['required' => true, 'span' => true]); ?>
                            <?php echo field_text('did_pattern', 'DID / number pattern', '', [
                                'required' => true, 'placeholder' => 'e.g. +15551234567 or _+1555XXXX',
                                'hint' => 'Prefix with _ for wildcards (X = digit). A bare number matches exactly.',
                            ]); ?>
                            <?php echo field_text('cid_pattern', 'Caller ID pattern (optional)', '', [
                                'hint' => 'Match on the caller ID as well.',
                            ]); ?>
                            <?php echo field_select('dest_type', 'Destination type', $typeLabels, 'extension', ['required' => true]); ?>
                            <?php echo field_select('dest_id', 'Destination', $destinations, null, ['placeholder' => 'Choose…']); ?>
                            <?php
                            $announcementOpts = [];
                            foreach ((array) ($announcements ?? []) as $a) { $announcementOpts[(int) $a['id']] = $a['name']; }
                            echo field_select('announcement_id', 'Custom prompt', $announcementOpts, null, [
                                'placeholder' => 'No custom prompt',
                                'hint' => 'Answers the inbound call and plays completely before routing; it cannot be skipped.',
                            ]);
                            ?>
                            <div class="field"><label>Prompt audio</label><a class="btn" href="/moh?add=announcement">Add Audio</a><span class="hint">Upload a new prompt, then return to select it.</span></div>
                            <?php echo field_text('priority', 'Priority', 0, ['type' => 'number',
                                'hint' => 'Lower numbers are evaluated first.']); ?>
                            <div class="span-2"><?php echo field_check('enabled', 'Route active', true); ?></div>
                            <?php echo field_select('record', 'Call recording', [
                                '0' => 'Tenant default',
                                '1' => 'Always record',
                                '2' => 'Never record',
                            ], '0', [
                                'hint' => 'Override the tenant-wide automatic recording setting for this inbound route.',
                            ]); ?>
                        </div>
                    </div>
                    <div class="modal-foot">
                        <button type="button" class="btn" data-modal-close>Cancel</button>
                        <button type="submit" class="btn btn-primary" id="inboundSubmit">Add route</button>
                    </div>
                </form>
            </div>
        </div>

    <!-- ======================= OUTBOUND ======================= -->
    <?php elseif ($activeTab === 'outbound'): ?>
        <div class="card-head">
            <h2>Outbound routes</h2>
            <button class="btn btn-primary btn-sm" data-modal-open="outboundModal">
                <?= $this->fragment('icons/plus') ?> Add outbound route
            </button>
        </div>
        <div class="card-body tight">
            <?php if (empty($outbound)): ?>
                <?= \App\Core\View::fragment('partials/empty', [
                    'icon' => 'route', 'title' => 'No outbound routes',
                    'message' => 'Define how dialed numbers are matched and which trunk carries them.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Name</th><th>Prefix</th><th>Match pattern</th><th>Trunk</th><th>Time condition</th><th class="num">Priority</th><th class="actions">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($outbound as $r): ?>
                                <tr>
                                    <td><strong><?= e($r['name']) ?></strong></td>
                                    <td><code class="mono"><?= e($r['prefix'] ?: '—') ?></code></td>
                                    <td><code class="mono"><?= e($r['match_pattern']) ?></code></td>
                                    <td><?= e($r['trunk_name'] ?? '—') ?></td>
                                    <td>
                                        <?php if (!empty($r['time_condition_name'])): ?>
                                            <span class="badge <?= $r['time_condition_enabled'] ? 'blue' : 'gray' ?>"><?= e($r['time_condition_name']) ?></span>
                                        <?php else: ?>
                                            <span class="muted">Always</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num"><?= (int) $r['priority'] ?></td>
                                    <td class="actions">
                                        <button class="btn btn-sm" data-modal-open="outboundModal"
                                                data-edit='<?= e(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                                        <form method="post" action="/routes/outbound/<?= (int) $r['id'] ?>">
                                            <?= csrf() ?><input type="hidden" name="_method" value="DELETE">
                                            <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                    data-confirm-btn="Delete outbound route <?= e($r['name']) ?>?">
                                                <?= $this->fragment('icons/trash') ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="modal-overlay" id="outboundModal" hidden>
            <div class="modal">
                <form method="post" action="/routes/outbound" id="outboundForm">
                    <?= csrf() ?>
                    <div class="modal-head">
                        <h2 id="outboundTitle">Add outbound route</h2>
                        <button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button>
                    </div>
                    <div class="modal-body">
                        <div class="hint mb-3">
                            <strong>Examples:</strong><br>
                            All numbers: prefix blank, pattern <code>_X.</code><br>
                            Bangladesh mobile: prefix blank, pattern <code>_01XXXXXXXXX</code><br>
                            Dial 9 for an outside line: prefix <code>9</code>, pattern <code>_X.</code> — the leading 9 is removed before sending to the trunk.
                        </div>
                        <div class="form-grid">
                            <?php echo field_text('name', 'Route name', '', ['required' => true, 'span' => true]); ?>
                            <?php echo field_text('prefix', 'Dial prefix (optional)', '', [
                                'hint' => 'Digits users dial before the number. This prefix is matched, then stripped before the trunk.',
                            ]); ?>
                            <?php echo field_text('match_pattern', 'Match pattern', '', [
                                'required' => true, 'placeholder' => 'e.g. _9X. or _1NXXNXXXXXX',
                                'hint' => 'Asterisk pattern: _ = wildcard prefix, X = digit, N 2-9, etc.',
                            ]); ?>
                            <?php
                            $trunkOpts = [];
                            foreach ($trunks as $t) { $trunkOpts[(int) $t['id']] = $t['name']; }
                            echo field_select('trunk_id', 'Trunk', $trunkOpts, null, ['required' => true, 'placeholder' => 'Choose a trunk…']);
                            ?>
                            <?php
                            $timeOpts = [];
                            foreach ($times as $time) {
                                if ($time['enabled']) { $timeOpts[(int) $time['id']] = $time['name']; }
                            }
                            echo field_select('time_condition_id', 'Time condition', $timeOpts, null, [
                                'placeholder' => 'Always allowed',
                                'hint' => 'When selected, this route works only during the condition’s in-hours period.',
                            ]);
                            ?>
                            <?php echo field_text('cid_override', 'Caller ID override', '', [
                                'hint' => 'Force outbound caller ID (leave blank to use the extension\'s).',
                            ]); ?>
                            <?php echo field_text('priority', 'Priority', 0, ['type' => 'number']); ?>
                            <div class="span-2"><?php echo field_check('enabled', 'Route active', true); ?></div>
                            <?php echo field_select('record', 'Call recording', [
                                '0' => 'Tenant default',
                                '1' => 'Always record',
                                '2' => 'Never record',
                            ], '0', [
                                'hint' => 'Override the tenant-wide automatic recording setting for this outbound route.',
                            ]); ?>
                        </div>
                    </div>
                    <div class="modal-foot">
                        <button type="button" class="btn" data-modal-close>Cancel</button>
                        <button type="submit" class="btn btn-primary" id="outboundSubmit">Add route</button>
                    </div>
                </form>
            </div>
        </div>

    <!-- ======================= TRUNKS ======================= -->
    <?php elseif ($activeTab === 'trunks'): ?>
        <div class="card-head">
            <h2>SIP trunks</h2>
            <button class="btn btn-primary btn-sm" data-modal-open="trunkModal">
                <?= $this->fragment('icons/plus') ?> Add trunk
            </button>
        </div>
        <div class="card-body tight">
            <?php if (empty($trunks)): ?>
                <?= \App\Core\View::fragment('partials/empty', [
                    'icon' => 'link', 'title' => 'No trunks configured',
                    'message' => 'Add the SIP trunk credentials provided by your phone service provider.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                         <thead><tr><th>Name</th><th>Host</th><th>Username</th><th>From domain</th><th>Registration</th><th>Uptime</th><th>Latency</th><th>Config</th><th class="actions">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($trunks as $t): ?>
                                <tr>
                                    <td><strong><?= e($t['name']) ?></strong></td>
                                    <td class="mono"><?= e($t['host']) ?></td>
                                    <td class="mono"><?= e($t['username']) ?></td>
                                    <td class="mono"><?= e($t['from_domain'] ?: '—') ?></td>
                                    <td>
                                        <?php if (!$t['enabled']): ?>
                                            <span class="badge gray"><span class="dot"></span>Disabled</span>
                                        <?php elseif (!$trunkStatusAvailable): ?>
                                            <span class="badge gray"><span class="dot"></span>Unknown</span>
                                        <?php elseif (strcasecmp((string) $t['registration_status'], 'Registered') === 0): ?>
                                            <span class="badge green"><span class="dot"></span>Registered</span>
                                        <?php elseif ($t['registration_status']): ?>
                                            <span class="badge red" title="Asterisk status: <?= e($t['registration_status']) ?>"><span class="dot"></span><?= e($t['registration_status']) ?></span>
                                        <?php else: ?>
                                            <span class="badge amber"><span class="dot"></span>Unregistered</span>
                                        <?php endif; ?>
                                     </td>
                                    <td>
                                        <?php if ($t['uptime_seconds'] !== null): ?>
                                            <strong class="mono"><?= e(format_duration((int) $t['uptime_seconds'])) ?></strong>
                                            <div class="text-xs muted">since <?= e(format_date($t['status_since'], 'M j, g:i A')) ?></div>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($t['latency_ms'] !== null): ?>
                                            <span class="mono"><strong><?= e((string) $t['latency_ms']) ?></strong> ms</span>
                                            <?php if ($t['active_contacts'] > 0): ?>
                                                <div class="text-xs muted"><?= (int) $t['active_contacts'] ?> active contact<?= $t['active_contacts'] === 1 ? '' : 's' ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $t['enabled'] ? '<span class="badge green">On</span>' : '<span class="badge gray">Off</span>' ?></td>
                                    <td class="actions">
                                        <button type="button" class="btn btn-sm"
                                                data-modal-open="trunkMonitor<?= (int) $t['id'] ?>">Monitor</button>
                                        <button class="btn btn-sm" data-modal-open="trunkModal"
                                                data-edit='<?= e(json_encode(array_diff_key($t, ['secret' => true]), JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                                        <form method="post" action="/routes/trunks/<?= (int) $t['id'] ?>">
                                            <?= csrf() ?><input type="hidden" name="_method" value="DELETE">
                                            <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                    data-confirm-btn="Delete trunk <?= e($t['name']) ?>?">
                                                <?= $this->fragment('icons/trash') ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php foreach ($trunks as $t):
            $segments = status_timeline($t['timeline_events'] ?? []);
            $history = $t['monitor_history'] ?? [];
        ?>
            <div class="modal-overlay" id="trunkMonitor<?= (int) $t['id'] ?>" hidden>
                <div class="modal lg">
                    <div class="modal-head">
                        <div>
                            <h2>Monitor · <?= e($t['name']) ?></h2>
                            <div class="text-xs muted mono"><?= e($t['host']) ?></div>
                        </div>
                        <button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button>
                    </div>
                    <div class="modal-body">
                        <div class="flex justify-between items-center mb-3">
                            <strong>24-hour availability</strong>
                            <span class="tl-legend">
                                <span><i class="ok"></i>Registered</span>
                                <span><i class="down"></i>Unregistered</span>
                                <span><i class="off"></i>Disabled</span>
                            </span>
                        </div>
                        <div class="tl" style="width:100%;min-width:0">
                            <?php foreach ($segments as $seg): ?>
                                <span class="tl-seg <?= e(status_timeline_class($seg['status'])) ?>"
                                      style="left:<?= $seg['left'] ?>%;width:<?= $seg['width'] ?>%"
                                      title="<?= e($seg['title']) ?>"></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex justify-between items-center mt-3">
                            <div>
                                <?php if (!$t['enabled']): ?>
                                    <span class="badge gray"><span class="dot"></span>Disabled</span>
                                <?php elseif (!$trunkStatusAvailable): ?>
                                    <span class="badge gray"><span class="dot"></span>Unknown</span>
                                <?php elseif (strcasecmp((string) $t['registration_status'], 'Registered') === 0): ?>
                                    <span class="badge green"><span class="dot"></span>Registered</span>
                                <?php elseif ($t['registration_status']): ?>
                                    <span class="badge red"><span class="dot"></span><?= e($t['registration_status']) ?></span>
                                <?php else: ?>
                                    <span class="badge amber"><span class="dot"></span>Unregistered</span>
                                <?php endif; ?>
                                <?php if ((int) $t['active_contacts'] > 0): ?>
                                    <span class="muted text-xs"> · <?= (int) $t['active_contacts'] ?> active contact<?= (int) $t['active_contacts'] === 1 ? '' : 's' ?></span>
                                <?php endif; ?>
                                <?php if ($t['latency_ms'] !== null): ?>
                                    <span class="muted text-xs"> · <span class="mono"><?= e((string) $t['latency_ms']) ?> ms</span></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($t['uptime_seconds'] !== null): ?>
                                <span class="muted text-xs">Up <?= e(format_duration((int) $t['uptime_seconds'])) ?> · since <?= e(format_date($t['status_since'], 'M j, g:i A')) ?></span>
                            <?php else: ?>
                                <span class="muted text-xs">Sampled every minute</span>
                            <?php endif; ?>
                        </div>

                        <div class="divider"></div>
                        <div class="flex justify-between items-center mb-3">
                            <strong>Registration uptime history</strong>
                            <span class="muted text-xs">Latest 50 state periods</span>
                        </div>
                        <?php if (empty($history)): ?>
                            <p class="muted mb-0">No status changes recorded yet. History accumulates every minute.</p>
                        <?php else: ?>
                            <div class="table-wrap">
                                <table>
                                    <thead><tr><th>Status</th><th>Started</th><th>Ended</th><th>Duration</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($history as $event):
                                        $start = strtotime((string) $event['started_at']) ?: time();
                                        $end = $event['ended_at'] ? (strtotime((string) $event['ended_at']) ?: time()) : time();
                                        $registered = strcasecmp((string) $event['status'], 'Registered') === 0;
                                    ?>
                                        <tr>
                                            <td><span class="badge <?= $registered ? 'green' : ((string) $event['status'] === 'Disabled' ? 'gray' : 'red') ?>"><span class="dot"></span><?= e($event['status']) ?></span></td>
                                            <td><?= e(format_date($event['started_at'])) ?></td>
                                            <td><?= $event['ended_at'] ? e(format_date($event['ended_at'])) : '<span class="badge blue">Current</span>' ?></td>
                                            <td class="mono"><?= e(format_duration(max(0, $end - $start))) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-foot"><button type="button" class="btn" data-modal-close>Close</button></div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="modal-overlay" id="trunkModal" hidden>
            <div class="modal">
                <form method="post" action="/routes/trunks" id="trunkForm" autocomplete="off">
                    <?= csrf() ?>
                    <div class="modal-head">
                        <h2 id="trunkTitle">Add trunk</h2>
                        <button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button>
                    </div>
                    <div class="modal-body">
                        <div class="form-grid">
                            <?php echo field_text('name', 'Trunk name', '', ['required' => true, 'span' => true]); ?>
                            <?php echo field_text('host', 'SIP host / proxy', '', ['required' => true, 'placeholder' => 'sip.provider.com']); ?>
                            <?php echo field_text('sip_trunk_auth_id', 'Username / auth ID', '', ['autocomplete' => 'off']); ?>
                            <?php echo field_text('sip_trunk_auth_secret', 'SIP trunk password', '', [
                                'type' => 'password', 'autocomplete' => 'new-password',
                                'hint' => 'Use your SIP provider password, not your panel login password. Leave blank when editing to keep the current password.']); ?>
                            <?php echo field_text('from_domain', 'From domain', '', ['hint' => 'Defaults to the host.']); ?>
                            <?php echo field_text('cid', 'Caller ID', '', ['placeholder' => 'Name <number>']); ?>
                            <?php echo field_text('register', 'Re-register every (seconds)', 300, ['type' => 'number']); ?>
                            <div class="span-2"><?php echo field_check('enabled', 'Trunk active', true); ?></div>
                        </div>
                    </div>
                    <div class="modal-foot">
                        <button type="button" class="btn" data-modal-close>Cancel</button>
                        <button type="submit" class="btn btn-primary" id="trunkSubmit">Add trunk</button>
                    </div>
                </form>
            </div>
        </div>

    <!-- ======================= TIME CONDITIONS ======================= -->
    <?php else: ?>
        <div class="card-head">
            <h2>Time conditions</h2>
            <button class="btn btn-primary btn-sm" data-modal-open="timeModal">
                <?= $this->fragment('icons/plus') ?> Add time condition
            </button>
        </div>
        <div class="card-body tight">
            <?php if (empty($times)): ?>
                <?= \App\Core\View::fragment('partials/empty', [
                    'icon' => 'clock', 'title' => 'No time conditions',
                    'message' => 'Route calls differently based on the time of day, day of week, or date.',
                ]) ?>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Name</th><th>Schedule</th><th>In hours</th><th>Out of hours</th><th class="actions">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($times as $t): ?>
                                <tr>
                                    <td><strong><?= e($t['name']) ?></strong></td>
                                    <td>
                                        <code class="text-xs mono"><?= e($t['time_match'] ?: ($t['days_of_week'] . ' ' . $t['start_hour'] . ':' . $t['start_minute'] . '-' . $t['end_hour'] . ':' . $t['end_minute'])) ?></code>
                                    </td>
                                    <td><span class="badge green"><?= e($t['open_label']) ?></span></td>
                                    <td><span class="badge amber"><?= e($t['closed_label']) ?></span></td>
                                    <td class="actions">
                                        <button class="btn btn-sm" data-modal-open="timeModal"
                                                data-edit='<?= e(json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>Edit</button>
                                        <form method="post" action="/routes/time/<?= (int) $t['id'] ?>">
                                            <?= csrf() ?><input type="hidden" name="_method" value="DELETE">
                                            <button class="btn btn-sm btn-ghost" style="color:var(--danger-500)"
                                                    data-confirm-btn="Delete time condition <?= e($t['name']) ?>?">
                                                <?= $this->fragment('icons/trash') ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="modal-overlay" id="timeModal" hidden>
            <div class="modal lg">
                <form method="post" action="/routes/time" id="timeForm">
                    <?= csrf() ?>
                    <div class="modal-head">
                        <h2 id="timeTitle">Add time condition</h2>
                        <button type="button" class="icon-btn" data-modal-close><?= $this->fragment('icons/x') ?></button>
                    </div>
                    <div class="modal-body">
                        <div class="form-grid">
                            <?php echo field_text('name', 'Condition name', '', ['required' => true, 'span' => true]); ?>
                            <?php echo field_text('extension', 'Condition number (optional)', '', [
                                'hint' => 'If set, this number routes through the condition.',
                            ]); ?>
                            <?php echo field_text('time_match', 'GotoIfTime expression', '', [
                                'span' => true,
                                'hint' => 'Asterisk format: times,weekdays,month-days,months. Example: 09:00-17:00,mon-fri,*,*. Leave blank to use the fields below.',
                            ]); ?>
                            <?php echo field_text('days_of_week', 'Days of week', '*', ['hint' => 'mon-fri, sat-sun, or *']); ?>
                            <?php echo field_text('day_of_month', 'Days of month', '*', ['hint' => '1-31 or *']); ?>
                            <?php echo field_text('month', 'Month', '*', ['hint' => '1-12 or *']); ?>
                            <?php echo field_text('start_hour', 'Start hour (0-23)', '', ['type' => 'number']); ?>
                            <?php echo field_text('start_minute', 'Start minute (0-59)', 0, ['type' => 'number']); ?>
                            <?php echo field_text('end_hour', 'End hour (0-23)', '', ['type' => 'number']); ?>
                            <?php echo field_text('end_minute', 'End minute (0-59)', 0, ['type' => 'number']); ?>
                            <?php
                            echo field_select('open_type', 'When in hours', $typeLabels, 'extension', ['required' => true]);
                            echo field_select('open_dest_id', 'In-hours destination', $destinations, null, ['placeholder' => 'Choose…']);
                            echo field_select('closed_type', 'When out of hours', $typeLabels, 'extension', ['required' => true]);
                            echo field_select('closed_dest_id', 'Out-of-hours destination', $destinations, null, ['placeholder' => 'Choose…']);
                            ?>
                            <div class="span-2"><?php echo field_check('enabled', 'Condition active', true); ?></div>
                        </div>
                    </div>
                    <div class="modal-foot">
                        <button type="button" class="btn" data-modal-close>Cancel</button>
                        <button type="submit" class="btn btn-primary" id="timeSubmit">Add condition</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    function bindDestinationFilter(form, typeName, destinationName) {
        var type = form.elements[typeName];
        var destination = form.elements[destinationName];
        if (!type || !destination) return;

        var placeholder = destination.querySelector('option[value=""]');
        placeholder = placeholder ? placeholder.cloneNode(true) : null;
        var groups = {};
        destination.querySelectorAll('optgroup[data-type]').forEach(function (group) {
            groups[group.getAttribute('data-type')] = group.cloneNode(true);
        });

        var apply = function () {
            var selected = type.value;
            var currentValue = destination.value;
            destination.replaceChildren();
            if (placeholder) destination.appendChild(placeholder.cloneNode(true));
            if (groups[selected]) {
                destination.appendChild(groups[selected].cloneNode(true));
                destination.value = currentValue;
            } else if (selected !== 'external' && selected !== 'hangup') {
                var empty = document.createElement('option');
                empty.disabled = true;
                empty.textContent = 'No ' + type.options[type.selectedIndex].text.toLowerCase() + ' destinations available';
                destination.appendChild(empty);
            }
            if (!destination.value) destination.value = '';
        };
        type.addEventListener('change', apply);
        apply();
    }

    var inboundForm = document.getElementById('inboundForm');
    var timeForm = document.getElementById('timeForm');
    if (inboundForm) bindDestinationFilter(inboundForm, 'dest_type', 'dest_id');
    if (timeForm) {
        bindDestinationFilter(timeForm, 'open_type', 'open_dest_id');
        bindDestinationFilter(timeForm, 'closed_type', 'closed_dest_id');
    }
    function refreshDestinationFilters(form) {
        ['dest_type', 'open_type', 'closed_type'].forEach(function (name) {
            var select = form.elements[name];
            if (select) select.dispatchEvent(new Event('change'));
        });
    }

    // Generic modal populate-on-edit for the four tabs.
    var configs = [
        { modal: 'inboundModal',  form: 'inboundForm',  title: 'inboundTitle',  submit: 'inboundSubmit',
          action: '/routes/inbound',  editAction: function (id) { return '/routes/inbound/' + id; },
          fields: ['name','did_pattern','cid_pattern','dest_type','dest_id','announcement_id','priority','record'],
          checks: [['enabled',1]], label: 'inbound route' },
        { modal: 'outboundModal', form: 'outboundForm', title: 'outboundTitle', submit: 'outboundSubmit',
          action: '/routes/outbound', editAction: function (id) { return '/routes/outbound/' + id; },
          fields: ['name','prefix','match_pattern','trunk_id','time_condition_id','cid_override','priority','record'],
          checks: [['enabled',1]], label: 'outbound route' },
        { modal: 'trunkModal',    form: 'trunkForm',    title: 'trunkTitle',    submit: 'trunkSubmit',
          action: '/routes/trunks', editAction: function (id) { return '/routes/trunks/' + id; },
           fields: ['name','host','from_domain','cid','register'],
          checks: [['enabled',1]], label: 'trunk' },
        { modal: 'timeModal',     form: 'timeForm',     title: 'timeTitle',     submit: 'timeSubmit',
          action: '/routes/time', editAction: function (id) { return '/routes/time/' + id; },
          fields: ['name','extension','time_match','days_of_week','day_of_month','month','start_hour','start_minute','end_hour','end_minute','open_type','open_dest_id','closed_type','closed_dest_id'],
          checks: [['enabled',1]], label: 'time condition' }
    ];

    configs.forEach(function (cfg) {
        var modal  = document.getElementById(cfg.modal);
        var form   = document.getElementById(cfg.form);
        var title  = document.getElementById(cfg.title);
        var submit = document.getElementById(cfg.submit);
        if (!modal || !form) return;

        document.querySelectorAll('[data-modal-open="' + cfg.modal + '"][data-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
            var data = JSON.parse(btn.getAttribute('data-edit'));
            form.action = cfg.editAction(data.id);
            title.textContent = 'Edit ' + cfg.label;
            submit.textContent = 'Save changes';

              cfg.fields.forEach(function (k) {
                  var el = form.elements[k];
                  if (el && data[k] !== undefined && data[k] !== null) {
                      el.value = data[k];
                      if (['dest_type', 'open_type', 'closed_type'].indexOf(k) !== -1) {
                          el.dispatchEvent(new Event('change'));
                      }
                  }
              });
              refreshDestinationFilters(form);
             if (cfg.modal === 'trunkModal') {
                 form.elements.sip_trunk_auth_id.value = data.username || '';
                 form.elements.sip_trunk_auth_secret.value = '';
             }
            cfg.checks.forEach(function (pair) {
                var el = form.elements[pair[0]];
                if (el) el.checked = Number(data[pair[0]]) === pair[1];
            });
            });
        });

        document.querySelectorAll('[data-modal-open="' + cfg.modal + '"]').forEach(function (opener) {
            if (opener.hasAttribute('data-edit')) return;
            opener.addEventListener('click', function () {
                form.reset();
                form.action = cfg.action;
                title.textContent = 'Add ' + cfg.label;
                submit.textContent = 'Add';
                refreshDestinationFilters(form);
            });
        });
    });
})();
</script>
