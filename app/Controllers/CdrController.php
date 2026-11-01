<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

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
}
