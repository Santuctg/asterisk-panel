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

        $where  = 'tenant_id = ?';
        $params = [$tid];

        if ($q !== '') {
            $where .= ' AND (src LIKE :q OR dst LIKE :q OR uniqueid LIKE :q)';
            $params[] = $q;
        }

        if ($disp !== '' && in_array($disp, ['ANSWERED', 'NO ANSWER', 'BUSY', 'FAILED', 'CONGESTION'], true)) {
            $where .= ' AND disposition = ?';
            $params[] = $disp;
        }

        $db     = $this->db();
        $total  = (int) $db->scalar("SELECT COUNT(*) FROM cdr WHERE {$where}", $params);
        $pages  = max(1, (int) ceil($total / $per));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $per;

        $rows = $db->all(
            "SELECT * FROM cdr WHERE {$where} ORDER BY calldate DESC LIMIT {$per} OFFSET {$offset}",
            $params
        );

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
            'stats' => $stats ?: ['total' => 0, 'answered' => 0, 'talktime' => 0],
        ]);
    }

    /** GET /cdr/{id}/recording — authorize, then delegate the file to Nginx. */
    public function recording(Request $request): Response
    {
        $row = $this->db()->one(
            'SELECT id, userfield FROM cdr WHERE id = ? AND tenant_id = ?',
            [(int) $request->params['id'], $this->tenantId()]
        );
        if (!$row || !str_starts_with((string) $row['userfield'], 'recording:')) {
            return Response::text('Recording not found.', 404);
        }

        $filename = substr((string) $row['userfield'], strlen('recording:'));
        if ($filename !== basename($filename)
            || str_contains($filename, '..')
            || !preg_match('/^[A-Za-z0-9_.-]+\.wav$/', $filename)) {
            return Response::text('Recording not found.', 404);
        }

        $dir = rtrim((string) Config::get('asterisk.recording_dir', '/var/spool/asterisk/monitor/asterisk-panel'), '/');
        $file = $dir . '/' . $filename;
        if (!is_file($file) || !is_readable($file)) {
            return Response::text('Recording not found.', 404);
        }

        return (new Response('', 200, [
            'Content-Type'        => 'audio/wav',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Accel-Redirect'    => '/_protected/recordings/' . rawurlencode($filename),
            'X-Content-Type-Options' => 'nosniff',
        ]));
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
        if (!str_starts_with($userfield, 'recording:')) { return; }
        $filename = substr($userfield, strlen('recording:'));
        if ($filename !== basename($filename) || !preg_match('/^[A-Za-z0-9_.-]+\.wav$/', $filename)) { return; }
        $dir = rtrim((string) Config::get('asterisk.recording_dir', '/var/spool/asterisk/monitor/asterisk-panel'), '/');
        $file = $dir . '/' . $filename;
        if (is_file($file)) { @unlink($file); }
    }
}
