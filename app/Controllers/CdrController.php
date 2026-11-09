<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Config;
use App\Core\Flash;

/**
 * Call detail records. Reads from the `cdr` table populated by Asterisk's
 * csv / http CDR backends (see docs/asterisk-setup.md) or the ingest hook.
 */
final class CdrController extends BaseController
{
    /** GET /cdr */
    public function index(Request $request): Response
    {
        $tid   = $this->tenantId();
        $page  = $request->page();
        $per   = $request->perPage();
        $q     = $request->str('q');
        $disp  = $request->str('disposition');
        $range = self::dateRange(
            $request->str('from'),
            $request->str('to'),
            new \DateTimeZone(date_default_timezone_get()),
            new \DateTimeZone((string) config('asterisk.cdr_timezone', 'UTC'))
        );

        $where  = 'tenant_id = ?';
        $params = [$tid];

        if ($q !== '') {
            $where .= " AND (src LIKE ? ESCAPE '!' OR dst LIKE ? ESCAPE '!' OR uniqueid LIKE ? ESCAPE '!')";
            $pattern = $this->likePattern($q);
            array_push($params, $pattern, $pattern, $pattern);
        }

        if ($disp !== '' && in_array($disp, ['ANSWERED', 'NO ANSWER', 'BUSY', 'FAILED', 'CONGESTION'], true)) {
            $where .= ' AND disposition = ?';
            $params[] = $disp;
        }
        if ($range['start'] !== null) { $where .= ' AND calldate >= ?'; $params[] = $range['start']; }
        if ($range['end'] !== null) { $where .= ' AND calldate < ?'; $params[] = $range['end']; }

        $db     = $this->db();
        $total  = (int) $db->scalar("SELECT COUNT(*) FROM cdr WHERE {$where}", $params);
        $pages  = max(1, (int) ceil($total / $per));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $per;

        $rows = $db->all(
            "SELECT * FROM cdr WHERE {$where} ORDER BY calldate DESC LIMIT {$per} OFFSET {$offset}",
            $params
        );
        foreach ($rows as &$row) {
            $recording = null;
            if ((string) ($row['disposition'] ?? '') === 'ANSWERED' && (int) ($row['billsec'] ?? 0) > 0) {
                $recording = $this->resolveRecording((string) ($row['userfield'] ?? ''));
            }
            $row['recording_available'] = $recording !== null;
            $row['recording_format'] = $recording['format'] ?? null;
        }
        unset($row);

        // Summary stats for the current filter.
        $stats = $db->one(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) AS answered,
                    SUM(billsec) AS talktime
             FROM cdr WHERE {$where}",
            $params
        );

        return $this->view('cdr/index', [
            'rows' => $rows, 'page' => $page, 'pages' => $pages,
            'total' => $total, 'q' => $q, 'disposition' => $disp,
            'from' => $range['from'], 'to' => $range['to'],
            'stats' => $stats ?: ['total' => 0, 'answered' => 0, 'talktime' => 0],
        ]);
    }

    /** Normalize tenant-local dates and return boundaries in the CDR storage timezone. */
    public static function dateRange(string $from, string $to, \DateTimeZone $tenantTimezone, \DateTimeZone $cdrTimezone): array
    {
        $parse = static function (string $value) use ($tenantTimezone): ?\DateTimeImmutable {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tenantTimezone);
            return $date && $date->format('Y-m-d') === $value ? $date : null;
        };
        $fromDate = $parse($from);
        $toDate = $parse($to);
        if ($fromDate && $toDate && $fromDate > $toDate) [$fromDate, $toDate] = [$toDate, $fromDate];
        return [
            'from' => $fromDate?->format('Y-m-d') ?? '',
            'to' => $toDate?->format('Y-m-d') ?? '',
            'start' => $fromDate?->setTimezone($cdrTimezone)->format('Y-m-d H:i:s'),
            'end' => $toDate?->modify('+1 day')->setTimezone($cdrTimezone)->format('Y-m-d H:i:s'),
        ];
    }

    /** GET /cdr/{id}/recording — authorize, then delegate the file to Nginx. */
    public function recording(Request $request): Response
    {
        $row = $this->db()->one(
            'SELECT id, userfield FROM cdr WHERE id = ? AND tenant_id = ?',
            [(int) $request->params['id'], $this->tenantId()]
        );
        if (!$row) {
            return Response::text('Recording not found.', 404);
        }

        $recording = $this->resolveRecording((string) $row['userfield']);
        if ($recording === null) {
            return Response::text('Recording not found.', 404);
        }
        $filename = $recording['filename'];

        $disposition = $request->bool('inline') ? 'inline' : 'attachment';

        return (new Response('', 200, [
            'Content-Type'        => $recording['mime'],
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
            'X-Accel-Redirect'    => '/_protected/recordings/' . rawurlencode($filename),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'       => 'private, no-store',
        ]));
    }

    /** Resolve a safe recording marker only when its protected file exists. */
    private function resolveRecording(string $userfield): ?array
    {
        foreach ($this->recordingCandidates($userfield) as $recording) {
            if (is_file($recording['path']) && is_readable($recording['path'])) {
                return $recording;
            }
        }
        return null;
    }

    /** DELETE /cdr/{id} — remove one tenant-scoped record and recording. */
    public function destroy(Request $request): Response
    {
        $row = $this->db()->one('SELECT * FROM cdr WHERE id = ? AND tenant_id = ?', [
            (int) $request->params['id'], $this->tenantId(),
        ]);
        if (!$row) { Flash::error('Call record not found.'); return Response::redirect('/cdr'); }
        $this->deleteRecording((string) ($row['userfield'] ?? ''));
        $this->db()->delete('cdr', (int) $row['id']);
        $this->audit('cdr.delete', 'cdr', (int) $row['id'], ['uniqueid' => $row['uniqueid'] ?? null]);
        Flash::success('Call record deleted.');
        return Response::redirect('/cdr');
    }

    /** POST /cdr/bulk-delete — remove multiple tenant-scoped records. */
    public function bulkDelete(Request $request): Response
    {
        $tid = $this->tenantId();
        if (!$tid) { return Response::redirect('/cdr'); }

        $ids = $request->input('ids');
        if (!is_array($ids) || $ids === []) {
            Flash::error('Select at least one call record to delete.');
            return Response::redirect('/cdr');
        }
        $ids = array_filter(array_unique(array_map('intval', $ids)), static fn (int $id): bool => $id > 0);
        if ($ids === []) {
            Flash::error('Select at least one call record to delete.');
            return Response::redirect('/cdr');
        }

        $ids   = array_values($ids);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows  = $this->db()->all(
            "SELECT id, userfield FROM cdr WHERE tenant_id = ? AND id IN ({$marks})",
            array_merge([$tid], $ids)
        );
        if ($rows === []) {
            Flash::error('No matching call records found.');
            return Response::redirect('/cdr');
        }

        foreach ($rows as $row) { $this->deleteRecording((string) ($row['userfield'] ?? '')); }

        $rowIds  = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $marks2  = implode(',', array_fill(0, count($rowIds), '?'));
        $count   = $this->db()->run(
            "DELETE FROM cdr WHERE tenant_id = ? AND id IN ({$marks2})",
            array_merge([$tid], $rowIds)
        )->rowCount();

        $this->audit('cdr.bulk_delete', 'tenant', $tid, ['records' => $count]);
        Flash::success("Deleted {$count} call record(s).");
        return Response::redirect('/cdr');
    }

    /** DELETE /cdr — clear call history for only the active tenant. */
    public function clear(Request $request): Response
    {
        $tid = $this->tenantId();
        if (!$tid) { return Response::redirect('/cdr'); }
        $rows = $this->db()->all('SELECT userfield FROM cdr WHERE tenant_id = ?', [$tid]);
        foreach ($rows as $row) { $this->deleteRecording((string) ($row['userfield'] ?? '')); }
        $count = $this->db()->run('DELETE FROM cdr WHERE tenant_id = ?', [$tid])->rowCount();
        $this->audit('cdr.clear', 'tenant', $tid, ['records' => $count]);
        Flash::success("Deleted {$count} call history record(s).");
        return Response::redirect('/cdr');
    }

    private function deleteRecording(string $userfield): void
    {
        foreach ($this->recordingCandidates($userfield) as $recording) {
            if (is_file($recording['path'])) { @unlink($recording['path']); }
        }
    }

    /** @return array<int,array{filename:string,path:string,mime:string,format:string}> */
    private function recordingCandidates(string $userfield): array
    {
        if (!str_starts_with($userfield, 'recording:')) { return []; }
        $marker = substr($userfield, strlen('recording:'));
        if ($marker === '' || $marker !== basename($marker) || str_contains($marker, '..')) { return []; }

        if (preg_match('/^[A-Za-z0-9_.-]+\.wav$/D', $marker)) {
            $files = [[$marker, 'audio/wav', 'WAV']];
        } elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,240}$/D', $marker)) {
            $files = [[$marker . '.ogg', 'audio/ogg', 'OPUS'], [$marker . '.wav', 'audio/wav', 'WAV']];
        } else {
            return [];
        }

        $dir = rtrim((string) Config::get('asterisk.recording_dir', '/var/spool/asterisk/monitor/asterisk-panel'), '/\\');
        return array_map(static fn (array $file): array => [
            'filename' => $file[0],
            'path' => $dir . DIRECTORY_SEPARATOR . $file[0],
            'mime' => $file[1],
            'format' => $file[2],
        ], $files);
    }
}
