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
        $upload = $this->uploadAudio($data['name'], $data['directory'], $request->files('audio'));
        if ($upload['error'] !== '') {
            Flash::warning($upload['error']);
        }
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
        $upload = $this->uploadAudio($data['name'], $data['directory'], $request->files('audio'));
        if ($upload['error'] !== '') {
            Flash::warning($upload['error']);
        }
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

    /** @return array{count:int,error:string} */
    private function uploadAudio(string $name, string $directory, ?array $files): array
    {
        if (!$files || !isset($files['name']) || !is_array($files['name'])) {
            return ['count' => 0, 'error' => ''];
        }

        $root = rtrim((string) config('asterisk.moh_path', '/var/lib/asterisk/moh'), '/');
        $dir = trim($directory) !== '' ? trim($directory) : $root . '/' . $name;
        $rootReal = realpath($root);
        $dirParent = dirname($dir);
        if ($rootReal === false || !is_dir($dirParent) && !@mkdir($dirParent, 0750, true)) {
            return ['count' => 0, 'error' => 'Audio directory is not available for upload.'];
        }

        $dirRealParent = realpath($dirParent);
        if ($dirRealParent === false || ($dirRealParent !== $rootReal && !str_starts_with($dirRealParent, $rootReal . DIRECTORY_SEPARATOR))) {
            return ['count' => 0, 'error' => 'Audio uploads must stay inside the Asterisk Music on Hold directory.'];
        }
        if (!is_dir($dir) && !@mkdir($dir, 02775, true)) {
            return ['count' => 0, 'error' => 'Could not create the audio directory.'];
        }
        @chmod($dir, 02775);

        $allowed = ['wav', 'gsm', 'ulaw', 'alaw', 'g722', 'sln', 'sln16', 'sln48', 'sln96', 'sln192', 'mp3', 'ogg'];
        $count = 0;
        $errors = [];
        foreach ($files['name'] as $i => $original) {
            $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) { continue; }
            if ($error !== UPLOAD_ERR_OK) { $errors[] = basename((string) $original); continue; }

            $filename = basename((string) $original);
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($filename === '' || !in_array($extension, $allowed, true) || !is_uploaded_file($files['tmp_name'][$i])) {
                $errors[] = $filename ?: 'unnamed file';
                continue;
            }
            if ((int) ($files['size'][$i] ?? 0) > 25 * 1024 * 1024) {
                $errors[] = $filename;
                continue;
            }

            $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: ('track_' . $i . '.' . $extension);
            $target = $dir . '/' . $safe;
            if (@move_uploaded_file($files['tmp_name'][$i], $target)) {
                @chmod($target, 0640);
                $count++;
            } else { $errors[] = $filename; }
        }

        if ($errors === []) { return ['count' => $count, 'error' => '']; }
        return ['count' => $count, 'error' => 'Some audio files could not be uploaded: ' . implode(', ', array_slice($errors, 0, 3)) . '.'];
    }
}
