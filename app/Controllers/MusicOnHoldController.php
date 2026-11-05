<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** Tenant-scoped Music on Hold playlists and tracks. */
final class MusicOnHoldController extends BaseController
{
    public function index(Request $request): Response
    {
        $tid = $this->requireTenant();
        $rows = $this->db()->all(
            'SELECT * FROM moh_classes WHERE tenant_id = ? ORDER BY name',
            [$tid]
        );
        foreach ($rows as &$row) {
            $row['track_count'] = count($this->tracks($row));
        }
        unset($row);

        $selected = null;
        $selectedId = $request->int('class');
        if ($selectedId > 0) {
            $selected = $this->findScoped($selectedId);
        }
        if ($selected === null && $rows) {
            $selected = $rows[0];
        }

        return $this->view('moh/index', [
            'rows' => $rows,
            'selected' => $selected,
            'tracks' => $selected ? $this->tracks($selected) : [],
            'announcements' => $this->db()->all(
                'SELECT * FROM announcements WHERE tenant_id = ? ORDER BY name',
                [$tid]
            ),
        ]);
    }

    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();
        $data = $this->classData($request);
        $validation = Validator::make($data, [
            'name' => 'required|alpha_dash|max:80',
        ]);
        if ($validation->fails()) {
            Flash::error($validation->firstError());
            return Response::redirect('/moh');
        }
        if ($this->db()->scalar('SELECT COUNT(*) FROM moh_classes WHERE tenant_id = ? AND name = ?', [$tid, $data['name']])) {
            Flash::error('A playlist with that name already exists.');
            return Response::redirect('/moh');
        }

        $data['tenant_id'] = $tid;
        $data['created_at'] = date('Y-m-d H:i:s');
        $id = $this->db()->insert('moh_classes', $data);
        $row = $this->findScoped($id);
        if (!$row || $this->classDirectory($row, true) === null) {
            $this->db()->delete('moh_classes', $id);
            Flash::error('The playlist audio directory could not be created.');
            return Response::redirect('/moh');
        }

        $this->audit('moh.create', 'moh_class', $id);
        $this->applyToAsterisk('moh');
        Flash::success('Music on Hold playlist created.');
        return Response::redirect('/moh?class=' . $id);
    }

    public function update(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) { return Response::redirect('/moh'); }
        $data = $this->classData($request);
        $validation = Validator::make($data, ['name' => 'required|alpha_dash|max:80']);
        if ($validation->fails()) {
            Flash::error($validation->firstError());
            return Response::redirect('/moh?class=' . (int) $row['id']);
        }
        if ($this->db()->scalar(
            'SELECT COUNT(*) FROM moh_classes WHERE tenant_id = ? AND name = ? AND id <> ?',
            [(int) $row['tenant_id'], $data['name'], (int) $row['id']]
        )) {
            Flash::error('A playlist with that name already exists.');
            return Response::redirect('/moh?class=' . (int) $row['id']);
        }

        $data['directory'] = $row['directory'];
        if ($data['name'] !== $row['name'] && trim((string) $row['directory']) === '') {
            // Keep existing audio in place when only the display/class name is renamed.
            $data['directory'] = $this->classDirectory($row);
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db()->update('moh_classes', (int) $row['id'], $data);
        if ($data['name'] !== $row['name']) {
            $this->db()->run(
                'UPDATE queues SET moh_class = ? WHERE tenant_id = ? AND moh_class = ?',
                [$data['name'], (int) $row['tenant_id'], $row['name']]
            );
            $this->db()->run(
                'UPDATE conferences SET moh_class = ? WHERE tenant_id = ? AND moh_class = ?',
                [$data['name'], (int) $row['tenant_id'], $row['name']]
            );
        }
        $this->audit('moh.update', 'moh_class', (int) $row['id']);
        $this->applyToAsterisk('moh');
        Flash::success('Playlist updated.');
        return Response::redirect('/moh?class=' . (int) $row['id']);
    }

    public function destroy(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) { return Response::redirect('/moh'); }
        $used = (int) $this->db()->scalar(
            'SELECT (SELECT COUNT(*) FROM queues WHERE tenant_id = ? AND moh_class = ?)
                  + (SELECT COUNT(*) FROM conferences WHERE tenant_id = ? AND moh_class = ?)',
            [(int) $row['tenant_id'], $row['name'], (int) $row['tenant_id'], $row['name']]
        );
        if ($used > 0) {
            Flash::error('This playlist is assigned to a queue or conference. Change those assignments first.');
            return Response::redirect('/moh?class=' . (int) $row['id']);
        }

        $dir = $this->classDirectory($row);
        if ($dir && $this->isManagedDirectory($row, $dir)) {
            $this->removeDirectory($dir);
        }
        $this->db()->delete('moh_classes', (int) $row['id']);
        $this->audit('moh.delete', 'moh_class', (int) $row['id']);
        $this->applyToAsterisk('moh');
        Flash::success('Playlist deleted.');
        return Response::redirect('/moh');
    }

    /** Upload one or more tracks and convert them to browser/Asterisk-safe WAV. */
    public function uploadTracks(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) { return Response::redirect('/moh'); }
        $result = $this->saveUploads($row, $request->files('audio'));
        if ($result['count'] > 0) {
            $this->audit('moh.track.upload', 'moh_class', (int) $row['id'], ['count' => $result['count']]);
            $this->applyToAsterisk('moh');
            Flash::success($result['count'] . ' track(s) uploaded.');
        }
        if ($result['error'] !== '') { Flash::warning($result['error']); }
        return Response::redirect('/moh?class=' . (int) $row['id']);
    }

    /** Browser MediaRecorder upload. */
    public function recordTrack(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) { return Response::json(['error' => 'Playlist not found.'], 404); }
        $result = $this->saveUploads($row, $request->files('audio'), $request->str('name', 'Browser recording'));
        if ($result['count'] < 1) {
            return Response::json(['error' => $result['error'] ?: 'Recording could not be saved.'], 422);
        }
        $this->audit('moh.track.record', 'moh_class', (int) $row['id']);
        $this->applyToAsterisk('moh');
        return Response::json(['ok' => true, 'redirect' => '/moh?class=' . (int) $row['id']]);
    }

    /** Generate speech locally with espeak-ng, then normalize it for Asterisk. */
    public function generateSpeech(Request $request): Response
    {
        $row = $this->findScoped((int) $request->params['id']);
        if (!$row) { return Response::redirect('/moh'); }
        $text = $request->str('text');
        $name = $request->str('name', 'Generated speech');
        $voice = $request->str('voice', 'en-us');
        $speed = max(80, min(300, $request->int('speed', 155)));
        $voices = ['en-us', 'en-gb', 'bn', 'hi'];
        if ($text === '' || mb_strlen($text) > 1500 || !in_array($voice, $voices, true)) {
            Flash::error('Enter up to 1,500 characters and choose a supported voice.');
            return Response::redirect('/moh?class=' . (int) $row['id']);
        }
        $dir = $this->classDirectory($row, true);
        if (!$dir) {
            Flash::error('The playlist directory is unavailable.');
            return Response::redirect('/moh?class=' . (int) $row['id']);
        }
        $target = $this->uniqueTarget($dir, $name);
        $temporary = tempnam(sys_get_temp_dir(), 'panel-tts-');
        $output = [];
        $code = 1;
        if ($temporary !== false) {
            $command = 'espeak-ng -v ' . escapeshellarg($voice) . ' -s ' . $speed
                . ' -w ' . escapeshellarg($temporary) . ' ' . escapeshellarg($text) . ' 2>&1';
            exec($command, $output, $code);
        }
        $converted = $code === 0 && $temporary !== false && $this->convertAudio($temporary, $target);
        if ($temporary !== false) { @unlink($temporary); }
        if (!$converted) {
            @unlink($target);
            Flash::error('Speech generation is unavailable. Install espeak-ng and try again.');
            return Response::redirect('/moh?class=' . (int) $row['id']);
        }
        @chmod($target, 0640);
        $this->audit('moh.track.speech', 'moh_class', (int) $row['id'], ['voice' => $voice]);
        $this->applyToAsterisk('moh');
        Flash::success('Generated speech added to the playlist.');
        return Response::redirect('/moh?class=' . (int) $row['id']);
    }

    public function toggleTrack(Request $request): Response
    {
        $resolved = $this->resolveTrack((int) $request->params['id'], (string) $request->params['filename']);
        if (!$resolved) { return Response::redirect('/moh'); }
        $row = $resolved['class'];
        $dir = $resolved['directory'];
        $filename = $resolved['filename'];
        $disabledDir = $dir . '/.disabled';
        if ($resolved['enabled']) {
            if (!is_dir($disabledDir)) { @mkdir($disabledDir, 02775, true); }
            $target = $disabledDir . '/' . $filename;
        } else {
            $target = $dir . '/' . $filename;
        }
        if (is_file($target) || !@rename($resolved['path'], $target)) {
            Flash::error('The track status could not be changed.');
        } else {
            @chmod($target, 0640);
            $this->audit('moh.track.toggle', 'moh_class', (int) $row['id'], ['filename' => $filename]);
            $this->applyToAsterisk('moh');
            Flash::success($resolved['enabled'] ? 'Track disabled.' : 'Track enabled.');
        }
        return Response::redirect('/moh?class=' . (int) $row['id']);
    }

    public function deleteTrack(Request $request): Response
    {
        $resolved = $this->resolveTrack((int) $request->params['id'], (string) $request->params['filename']);
        if (!$resolved) { return Response::redirect('/moh'); }
        if (!@unlink($resolved['path'])) {
            Flash::error('The track could not be deleted.');
        } else {
            $this->audit('moh.track.delete', 'moh_class', (int) $resolved['class']['id'], ['filename' => $resolved['filename']]);
            $this->applyToAsterisk('moh');
            Flash::success('Track deleted.');
        }
        return Response::redirect('/moh?class=' . (int) $resolved['class']['id']);
    }

    /** Authorize a tenant track, then delegate range/download handling to Nginx. */
    public function trackAudio(Request $request): Response
    {
        $resolved = $this->resolveTrack((int) $request->params['id'], (string) $request->params['filename']);
        if (!$resolved) { return Response::text('Track not found.', 404); }
        $disposition = $request->bool('download') ? 'attachment' : 'inline';
        $relative = $this->relativeToMohRoot($resolved['path']);
        if ($relative === null) { return Response::text('Track not found.', 404); }
        $internal = implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', $relative))));
        return new Response('', 200, [
            'Content-Type' => 'audio/wav',
            'Content-Disposition' => $disposition . '; filename="' . $resolved['filename'] . '"',
            'X-Accel-Redirect' => '/_protected/moh/' . $internal,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function classData(Request $request): array
    {
        return [
            'name' => $request->str('name'),
            'directory' => null,
            'random' => $request->bool('random', true) ? 1 : 0,
            'shuffle' => $request->bool('shuffle') ? 1 : 0,
        ];
    }

    private function findScoped(int $id): ?array
    {
        if ($id <= 0) { return null; }
        return $this->db()->one(
            'SELECT * FROM moh_classes WHERE id = ? AND tenant_id = ?',
            [$id, $this->tenantId()]
        );
    }

    private function classDirectory(array $row, bool $create = false): ?string
    {
        $root = rtrim((string) config('asterisk.moh_path', '/var/lib/asterisk/moh'), '/\\');
        $rootReal = realpath($root);
        if ($rootReal === false) { return null; }
        $directory = trim((string) ($row['directory'] ?? ''));
        if ($directory === '') {
            $context = (string) $this->db()->scalar('SELECT context FROM tenants WHERE id = ?', [(int) $row['tenant_id']]);
            $context = preg_replace('/[^A-Za-z0-9_-]+/', '_', $context) ?: ('tenant_' . (int) $row['tenant_id']);
            $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $row['name']);
            $directory = $root . '/' . $context . '/' . $name;
        }
        if ($create && !is_dir($directory) && !@mkdir($directory, 02775, true)) { return null; }
        $real = realpath($directory);
        if ($real === false || ($real !== $rootReal && !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR))) { return null; }
        if ($create) { @chmod($real, 02775); }
        return $real;
    }

    private function tracks(array $row): array
    {
        $dir = $this->classDirectory($row);
        if (!$dir) { return []; }
        $tracks = [];
        foreach ([[true, $dir], [false, $dir . '/.disabled']] as [$enabled, $folder]) {
            if (!is_dir($folder)) { continue; }
            foreach (scandir($folder) ?: [] as $filename) {
                if (!$this->safeTrackName($filename) || !is_file($folder . '/' . $filename)) { continue; }
                $path = $folder . '/' . $filename;
                $tracks[] = [
                    'filename' => $filename,
                    'enabled' => $enabled,
                    'size' => filesize($path) ?: 0,
                    'duration' => $this->duration($path),
                ];
            }
        }
        usort($tracks, static fn (array $a, array $b): int => strcasecmp($a['filename'], $b['filename']));
        return $tracks;
    }

    private function resolveTrack(int $classId, string $filename): ?array
    {
        $row = $this->findScoped($classId);
        $filename = rawurldecode($filename);
        if (!$row || !$this->safeTrackName($filename)) { return null; }
        $dir = $this->classDirectory($row);
        if (!$dir) { return null; }
        foreach ([[true, $dir . '/' . $filename], [false, $dir . '/.disabled/' . $filename]] as [$enabled, $path]) {
            if (is_file($path) && is_readable($path)) {
                return ['class' => $row, 'directory' => $dir, 'filename' => $filename, 'path' => $path, 'enabled' => $enabled];
            }
        }
        return null;
    }

    /** @return array{count:int,error:string} */
    private function saveUploads(array $row, ?array $files, ?string $forcedName = null): array
    {
        if (!$files || !isset($files['name'])) { return ['count' => 0, 'error' => 'Choose an audio file.']; }
        $names = is_array($files['name']) ? $files['name'] : [$files['name']];
        $errors = is_array($files['error'] ?? null) ? $files['error'] : [$files['error'] ?? UPLOAD_ERR_NO_FILE];
        $sizes = is_array($files['size'] ?? null) ? $files['size'] : [$files['size'] ?? 0];
        $temporary = is_array($files['tmp_name'] ?? null) ? $files['tmp_name'] : [$files['tmp_name'] ?? ''];
        $allowed = ['wav', 'mp3', 'ogg', 'flac', 'gsm', 'ulaw', 'alaw', 'g722', 'sln', 'webm', 'm4a', 'mp4'];
        $dir = $this->classDirectory($row, true);
        if (!$dir) { return ['count' => 0, 'error' => 'The playlist directory is unavailable.']; }
        $count = 0;
        $failed = [];
        foreach ($names as $index => $original) {
            $extension = strtolower(pathinfo((string) $original, PATHINFO_EXTENSION));
            $tmp = (string) ($temporary[$index] ?? '');
            $uploadError = (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            $displayName = basename((string) $original) ?: 'audio';
            if ($uploadError !== UPLOAD_ERR_OK) {
                $failed[] = $displayName . ' (' . $this->uploadErrorMessage($uploadError) . ')';
                continue;
            }
            if ((int) ($sizes[$index] ?? 0) > 25 * 1024 * 1024) {
                $failed[] = $displayName . ' (larger than 25 MB)';
                continue;
            }
            if (!in_array($extension, $allowed, true)) {
                $failed[] = $displayName . ' (unsupported format)';
                continue;
            }
            if (!is_uploaded_file($tmp)) {
                $failed[] = $displayName . ' (invalid upload)';
                continue;
            }
            $label = $forcedName ?? pathinfo((string) $original, PATHINFO_FILENAME);
            $target = $this->uniqueTarget($dir, $label);
            if ($this->convertAudio($tmp, $target)) {
                @chmod($target, 0640);
                $count++;
            } else {
                @unlink($target);
                $failed[] = basename((string) $original);
            }
        }
        return [
            'count' => $count,
            'error' => $failed ? 'Could not upload: ' . implode(', ', array_slice($failed, 0, 3)) . '.' : '',
        ];
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file exceeds the server upload limit',
            UPLOAD_ERR_PARTIAL => 'upload was interrupted',
            UPLOAD_ERR_NO_FILE => 'no file received',
            UPLOAD_ERR_NO_TMP_DIR => 'temporary upload directory is missing',
            UPLOAD_ERR_CANT_WRITE => 'server could not write the upload',
            UPLOAD_ERR_EXTENSION => 'upload was stopped by a server extension',
            default => 'upload failed',
        };
    }

    private function convertAudio(string $source, string $target): bool
    {
        $commands = [
            'sox ' . escapeshellarg($source) . ' -r 8000 -c 1 -e signed-integer -b 16 ' . escapeshellarg($target) . ' 2>&1',
            'ffmpeg -nostdin -loglevel error -y -i ' . escapeshellarg($source) . ' -ar 8000 -ac 1 -c:a pcm_s16le ' . escapeshellarg($target) . ' 2>&1',
        ];
        foreach ($commands as $command) {
            @unlink($target);
            $output = [];
            $code = 1;
            exec($command, $output, $code);
            if ($code === 0 && is_file($target) && (filesize($target) ?: 0) > 44) { return true; }
        }
        return false;
    }

    private function uniqueTarget(string $dir, string $name): string
    {
        $base = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($name, PATHINFO_FILENAME)), '_-');
        if ($base === '') { $base = 'track'; }
        $base = substr($base, 0, 90);
        $target = $dir . '/' . $base . '.wav';
        $suffix = 2;
        while (is_file($target) || is_file($dir . '/.disabled/' . basename($target))) {
            $target = $dir . '/' . $base . '_' . $suffix++ . '.wav';
        }
        return $target;
    }

    private function safeTrackName(string $filename): bool
    {
        return $filename !== '' && $filename === basename($filename)
            && !str_contains($filename, '..')
            && (bool) preg_match('/^[A-Za-z0-9_.-]+\.wav$/i', $filename);
    }

    private function duration(string $path): float
    {
        if (PHP_OS_FAMILY === 'Windows') { return 0.0; }
        $output = [];
        $code = 1;
        exec('soxi -D ' . escapeshellarg($path) . ' 2>/dev/null', $output, $code);
        return $code === 0 && isset($output[0]) && is_numeric($output[0]) ? round((float) $output[0], 1) : 0.0;
    }

    private function relativeToMohRoot(string $path): ?string
    {
        $root = realpath((string) config('asterisk.moh_path', '/var/lib/asterisk/moh'));
        $real = realpath($path);
        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) { return null; }
        return substr($real, strlen($root) + 1);
    }

    private function isManagedDirectory(array $row, string $directory): bool
    {
        if (trim((string) ($row['directory'] ?? '')) === '') { return true; }
        $root = realpath((string) config('asterisk.moh_path', '/var/lib/asterisk/moh'));
        $context = (string) $this->db()->scalar('SELECT context FROM tenants WHERE id = ?', [(int) $row['tenant_id']]);
        $context = preg_replace('/[^A-Za-z0-9_-]+/', '_', $context) ?: ('tenant_' . (int) $row['tenant_id']);
        $tenantRoot = $root === false ? false : realpath($root . '/' . $context);
        return $tenantRoot !== false
            && ($directory === $tenantRoot || str_starts_with($directory, $tenantRoot . DIRECTORY_SEPARATOR));
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) { $this->removeDirectory($path); }
            elseif (is_file($path) || is_link($path)) { @unlink($path); }
        }
        @rmdir($directory);
    }
}
