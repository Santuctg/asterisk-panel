<?php
/**
 * Call detail records (CDR). Expects: $rows, $page, $pages, $total, $q,
 * $disposition, $stats
 */
require dirname(__DIR__) . '/partials/fields.php';

$dispositions = ['' => 'All results', 'ANSWERED' => 'Answered', 'NO ANSWER' => 'No answer',
                 'BUSY' => 'Busy', 'FAILED' => 'Failed', 'CONGESTION' => 'Congestion'];

$answeredRate = ($stats['total'] ?? 0) > 0
    ? round(((int) $stats['answered'] / (int) $stats['total']) * 100)
    : 0;
?>

<div class="page-head">
    <div>
        <h1>Call History</h1>
        <div class="subtitle">Call detail records for the active tenant</div>
    </div>
</div>

<div class="grid grid-3 mb-4">
    <div class="stat">
        <div class="stat-icon"><?= $this->fragment('icons/clock') ?></div>
        <div class="stat-body">
            <div class="stat-value"><?= (int) $stats['total'] ?></div>
            <div class="stat-label">Total calls (filtered)</div>
        </div>
    </div>
    <div class="stat">
        <div class="stat-icon green"><?= $this->fragment('icons/check') ?></div>
        <div class="stat-body">
            <div class="stat-value"><?= (int) $stats['answered'] ?></div>
            <div class="stat-label">Answered (<?= $answeredRate ?>%)</div>
        </div>
    </div>
    <div class="stat">
        <div class="stat-icon violet"><?= $this->fragment('icons/pulse') ?></div>
        <div class="stat-body">
            <div class="stat-value"><?= e(format_duration((int) ($stats['talktime'] ?? 0))) ?></div>
            <div class="stat-label">Total talk time</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="toolbar">
        <form method="get" action="/cdr" class="flex gap-2 items-center w-full">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search source, destination or uniqueid…"
                   style="max-width:320px">
            <select name="disposition" data-auto-submit style="max-width:180px">
                <?php foreach ($dispositions as $val => $lab): ?>
                    <option value="<?= e($val) ?>" <?= $disposition === $val ? 'selected' : '' ?>><?= e($lab) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn">Filter</button>
            <span class="spacer"></span>
            <span class="muted text-sm"><?= (int) $total ?> record(s)</span>
        </form>
    </div>

    <div class="card-body tight">
        <?php if (empty($rows)): ?>
            <?= \App\Core\View::fragment('partials/empty', [
                'icon'   => 'clock',
                'title'  => 'No call records',
                'message'=> 'Configure Asterisk CDR collection (csv or http backend) to populate this table. See docs/asterisk-setup.md.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date &amp; time</th>
                            <th>Source</th>
                            <th>Destination</th>
                            <th>Context</th>
                            <th>Result</th>
                            <th class="num">Duration</th>
                            <th class="num">Billable</th>
                            <th>Account</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $c): ?>
                            <?php
                            $tone = match ($c['disposition']) {
                                'ANSWERED'  => 'green',
                                'BUSY'      => 'amber',
                                'NO ANSWER' => 'gray',
                                default     => 'red',
                            };
                            ?>
                            <tr>
                                <td class="nowrap muted text-xs"><?= e(format_date($c['calldate'])) ?></td>
                                <td class="mono"><?= e($c['src']) ?></td>
                                <td class="mono"><?= e($c['dst']) ?></td>
                                <td class="muted"><?= e($c['dcontext']) ?></td>
                                <td><span class="badge <?= $tone ?>"><?= e($c['disposition']) ?></span></td>
                                <td class="num mono"><?= e(format_duration((int) $c['duration'])) ?></td>
                                <td class="num mono"><?= e(format_duration((int) $c['billsec'])) ?></td>
                                <td class="muted"><?= e($c['accountcode'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="card-foot">
            <?= \App\Core\View::fragment('partials/pagination', [
                'page' => $page, 'pages' => $pages,
                'base' => '/cdr' . ($q ? '?q=' . urlencode($q) : '') . ($disposition ? '&disposition=' . urlencode($disposition) : ''),
            ]) ?>
            <span class="muted text-sm">Page <?= $page ?> of <?= $pages ?></span>
        </div>
    <?php endif; ?>
</div>
