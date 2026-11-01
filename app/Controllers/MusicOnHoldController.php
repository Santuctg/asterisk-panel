<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Music on hold classes. Each class points at a directory of audio files
 * under the Asterisk moh root (res_musiconhold).
 */
final class MusicOnHoldController extends BaseController
{
    /** GET /moh */
    public function index(Request $request): Response
    {
        $rows = $this->db()->all('SELECT * FROM moh_classes ORDER BY name');

        // Best-effort: list files in each class directory for the UI.
        $root = (string) config('asterisk.moh_path', '/var/lib/asterisk/moh');
        foreach ($rows as &$r) {
            $dir = $r['directory'] ?: ($root . '/' . $r['name']);
            $r['resolved_dir'] = $dir;
            $r['files'] = is_dir($dir) ? array_values(array_diff(scandir($dir) ?: [], ['.', '..'])) : [];
        }
        unset($r);

        return $this->view('moh/index', ['rows' => $rows]);
    }

    /** POST /moh */
    public function store(Request $request): Response
    {
        $data = [
            'name'      => $request->str('name'),
            'directory' => $request->str('directory'),
            'random'    => $request->bool('random', true) ? 1 : 0,
            'shuffle'   => $request->bool('shuffle') ? 1 : 0,
        ];

        $v = Validator::make($data, [
            'name'      => 'required|alpha_dash|max:80',
            'directory' => 'nullable|max:255',
        ]);

        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/moh');
        }

        $dup = $this->db()->scalar('SELECT COUNT(*) FROM moh_classes WHERE name = ?', [$data['name']]);
        if ($dup) {
            Flash::error('A music-on-hold class with that name already exists.');
            return Response::redirect('/moh');
        }

        $id = $this->db()->insert('moh_classes', $data);
        $this->audit('moh.create', 'moh_class', $id);
        $this->applyToAsterisk('moh');

        Flash::success('Music-on-hold class created.');
        return Response::redirect('/moh');
    }

    /** POST /moh/{id} */
    public function update(Request $request): Response
    {
        $row = $this->db()->one('SELECT * FROM moh_classes WHERE id = ?', [(int) $request->params['id']]);
        if (!$row) {
            return Response::redirect('/moh');
        }

        $data = [
            'name'      => $request->str('name', $row['name']),
            'directory' => $request->str('directory'),
            'random'    => $request->bool('random', true) ? 1 : 0,
            'shuffle'   => $request->bool('shuffle') ? 1 : 0,
        ];

        $v = Validator::make($data, [
            'name'      => 'required|alpha_dash|max:80',
            'directory' => 'nullable|max:255',
        ]);
        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/moh');
        }

        $this->db()->update('moh_classes', (int) $row['id'], $data);
        $this->audit('moh.update', 'moh_class', (int) $row['id']);
        $this->applyToAsterisk('moh');

        Flash::success('Music-on-hold class updated.');
        return Response::redirect('/moh');
    }

    /** DELETE /moh/{id} */
    public function destroy(Request $request): Response
    {
        $row = $this->db()->one('SELECT * FROM moh_classes WHERE id = ?', [(int) $request->params['id']]);
        if (!$row) {
            return Response::redirect('/moh');
        }

        $this->db()->delete('moh_classes', (int) $row['id']);
        $this->audit('moh.delete', 'moh_class', (int) $row['id']);
        $this->applyToAsterisk('moh');

        Flash::success('Music-on-hold class deleted.');
        return Response::redirect('/moh');
    }
}
