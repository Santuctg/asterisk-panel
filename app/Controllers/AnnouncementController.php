<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** Tenant-scoped, non-skippable caller announcements. */
final class AnnouncementController extends BaseController
{
    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();
        $name = $request->str('name');
        $v = Validator::make(['name' => $name], ['name' => 'required|max:120']);
        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/moh');
        }
        if ($this->db()->scalar('SELECT COUNT(*) FROM announcements WHERE tenant_id = ? AND name = ?', [$tid, $name])) {
            Flash::error('An announcement with that name already exists.');
            return Response::redirect('/moh');
        }

        $upload = $this->upload($tid, $request->files('audio'));
        if (isset($upload['error'])) {
            Flash::error($upload['error']);
            return Response::redirect('/moh');
        }

        $id = $this->db()->insert('announcements', [
            'tenant_id' => $tid,
            'name' => $name,
            'filename' => $upload['filename'],
            'sound_path' => $upload['sound_path'],
            'enabled' => $request->bool('enabled', true) ? 1 : 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->audit('announcement.create', 'announcement', $id);
        $this->applyToAsterisk('announcement');
        Flash::success('Announcement uploaded.');
        return Response::redirect('/moh');
    }

    public function update(Request $request): Response
    {
        $tid = $this->requireTenant();
        $row = $this->db()->one('SELECT * FROM announcements WHERE id = ? AND tenant_id = ?', [
            (int) $request->params['id'], $tid,
        ]);
        if (!$row) { return Response::redirect('/moh'); }

        $name = $request->str('name');
        if ($name === '') {
            Flash::error('Announcement name is required.');
            return Response::redirect('/moh');
        }
        if ($this->db()->scalar(
            'SELECT COUNT(*) FROM announcements WHERE tenant_id = ? AND name = ? AND id <> ?',
            [$tid, $name, (int) $row['id']]
        )) {
            Flash::error('An announcement with that name already exists.');
            return Response::redirect('/moh');
        }
        $data = [
            'name' => $name,
            'enabled' => $request->bool('enabled', true) ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $file = $request->files('audio');
        if ($file && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = $this->upload($tid, $file);
            if (isset($upload['error'])) {
                Flash::error($upload['error']);
                return Response::redirect('/moh');
            }
            $data['filename'] = $upload['filename'];
            $data['sound_path'] = $upload['sound_path'];
            $this->deleteFile((string) $row['filename'], $tid);
        }
        $this->db()->update('announcements', (int) $row['id'], $data);
        $this->audit('announcement.update', 'announcement', (int) $row['id']);
        $this->applyToAsterisk('announcement');
        Flash::success('Announcement updated.');
        return Response::redirect('/moh');
    }

    public function destroy(Request $request): Response
    {
        $tid = $this->requireTenant();
        $row = $this->db()->one('SELECT * FROM announcements WHERE id = ? AND tenant_id = ?', [
            (int) $request->params['id'], $tid,
        ]);
        if (!$row) { return Response::redirect('/moh'); }
        foreach (['ring_groups', 'inbound_routes', 'queues'] as $table) {
            $this->db()->run("UPDATE {$table} SET announcement_id = NULL WHERE tenant_id = ? AND announcement_id = ?", [$tid, (int) $row['id']]);
        }
        $this->db()->delete('announcements', (int) $row['id']);
        $this->deleteFile((string) $row['filename'], $tid);
        $this->audit('announcement.delete', 'announcement', (int) $row['id']);
        $this->applyToAsterisk('announcement');
        Flash::success('Announcement deleted.');
        return Response::redirect('/moh');
    }

    /** @return array{filename:string,sound_path:string}|array{error:string} */
    private function upload(int $tenantId, ?array $file): array
    {
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['error' => 'Choose an audio file to upload.'];
        }
        if ((int) ($file['size'] ?? 0) > 25 * 1024 * 1024 || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return ['error' => 'The announcement file is invalid or larger than 25 MB.'];
        }
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['wav', 'mp3', 'ogg', 'flac', 'gsm', 'ulaw', 'alaw'], true)) {
            return ['error' => 'Use WAV, MP3, OGG, FLAC, GSM, ULAW or ALAW audio.'];
        }
        $tenant = $this->db()->one('SELECT context FROM tenants WHERE id = ?', [$tenantId]);
        if (!$tenant) { return ['error' => 'Tenant not found.']; }
        $context = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $tenant['context']) ?: ('tenant_' . $tenantId);
        $root = rtrim((string) config('asterisk.announcement_dir', '/var/lib/asterisk/sounds/asterisk-panel'), '/');
        $dir = $root . '/' . $context;
        if (!is_dir($dir) && !@mkdir($dir, 02775, true)) {
            return ['error' => 'The announcement directory is not writable.'];
        }
        @chmod($dir, 02775);
        $base = 'announcement_' . bin2hex(random_bytes(8));
        $filename = $base . '.wav';
        $target = $dir . '/' . $filename;
        $command = 'sox ' . escapeshellarg((string) $file['tmp_name'])
            . ' -r 8000 -c 1 -e signed-integer -b 16 ' . escapeshellarg($target) . ' 2>&1';
        $output = [];
        $code = 1;
        exec($command, $output, $code);
        if ($code !== 0 || !is_file($target) || filesize($target) <= 44) {
            @unlink($target);
            return ['error' => 'Audio conversion failed. Verify the file and try again.'];
        }
        @chmod($target, 0640);
        return ['filename' => $filename, 'sound_path' => 'asterisk-panel/' . $context . '/' . $base];
    }

    private function deleteFile(string $filename, int $tenantId): void
    {
        if ($filename !== basename($filename) || !preg_match('/^[A-Za-z0-9_.-]+\.wav$/', $filename)) { return; }
        $tenant = $this->db()->one('SELECT context FROM tenants WHERE id = ?', [$tenantId]);
        if (!$tenant) { return; }
        $context = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $tenant['context']) ?: ('tenant_' . $tenantId);
        $root = rtrim((string) config('asterisk.announcement_dir', '/var/lib/asterisk/sounds/asterisk-panel'), '/');
        $path = $root . '/' . $context . '/' . $filename;
        if (is_file($path)) { @unlink($path); }
    }
}
