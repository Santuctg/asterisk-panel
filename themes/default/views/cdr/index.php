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
    <?php if (auth()->can('cdr.delete') && $total > 0): ?>
        <div class="page-actions">
            <form method="post" action="/cdr/bulk-delete" id="cdrBulkForm">
                <?= csrf() ?>
                <button class="btn btn-danger" data-bulk-submit data-confirm-btn="Delete the selected call records and their recordings?">Delete selected</button>
            </form>
            <form method="post" action="/cdr">
                <?= csrf() ?><input type="hidden" name="_method" value="DELETE">
                <button class="btn btn-ghost" data-confirm-btn="Delete all call history and recordings for this tenant?">Delete all history</button>
            </form>
        </div>
    <?php endif; ?>
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
                            <?php if (auth()->can('cdr.delete')): ?>
                                <th class="col-check"><input type="checkbox" data-select-all=".cdr-check" aria-label="Select all rows"></th>
                            <?php endif; ?>
                            <th>Date &amp; time</th>
                            <th>Source</th>
                            <th>Destination</th>
                            <th>Context</th>
                            <th>Result</th>
                            <th class="num">Duration</th>
                            <th class="num">Billable</th>
                            <th>Recording</th>
                            <th>Account</th>
                            <?php if (auth()->can('cdr.delete')): ?><th class="actions">Actions</th><?php endif; ?>
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
                                <?php if (auth()->can('cdr.delete')): ?>
                                    <td class="col-check"><input type="checkbox" class="cdr-check" name="ids[]" value="<?= (int) $c['id'] ?>" form="cdrBulkForm"></td>
                                <?php endif; ?>
                                <td class="nowrap muted text-xs"><?= e(format_date($c['calldate'])) ?></td>
                                <td class="mono"><?= e($c['src']) ?></td>
                                <td class="mono"><?= e($c['dst']) ?></td>
                                <td class="muted"><?= e($c['dcontext']) ?></td>
                                <td><span class="badge <?= $tone ?>"><?= e($c['disposition']) ?></span></td>
                                <td class="num mono"><?= e(format_duration((int) $c['duration'])) ?></td>
                                <td class="num mono"><?= e(format_duration((int) $c['billsec'])) ?></td>
                                <td>
                                    <?php if (str_starts_with((string) ($c['userfield'] ?? ''), 'recording:')): ?>
                                        <a class="btn btn-sm" href="/cdr/<?= (int) $c['id'] ?>/recording">Download WAV</a>
                                    <?php else: ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="muted"><?= e($c['accountcode'] ?: '—') ?></td>
                                <?php if (auth()->can('cdr.delete')): ?>
                                    <td class="actions"><form method="post" action="/cdr/<?= (int)$c['id'] ?>">
                                        <?= csrf() ?><input type="hidden" name="_method" value="DELETE">
                                        <button class="btn btn-sm btn-ghost" data-confirm-btn="Delete this call record and its recording?">Delete</button>
                                    </form></td>
                                <?php endif; ?>
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
