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
    private const DEST_TABLES = [
        'extension' => 'extensions', 'ringgroup' => 'ring_groups', 'ivr' => 'ivr_menus',
        'queue' => 'queues', 'conference' => 'conferences', 'parking' => 'parking_lots',
        'timecond' => 'time_conditions',
    ];

    public function store(Request $request): Response
    {
        $tid = $this->requireTenant();
        $file = $request->files('audio');
        $name = $request->str('name');
        if ($name === '' && $file) {
            $name = trim((string) preg_replace(
                '/[_-]+/',
                ' ',
                pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME)
            ));
        }
        $v = Validator::make(['name' => $name], ['name' => 'required|max:120']);
        if ($v->fails()) {
            Flash::error($v->firstError());
            return Response::redirect('/moh');
        }
        if ($this->db()->scalar('SELECT COUNT(*) FROM announcements WHERE tenant_id = ? AND name = ?', [$tid, $name])) {
            Flash::error('An announcement with that name already exists.');
            return Response::redirect('/moh');
        }
        $destination = $this->destination($request, $tid);
        if ($destination === null) {
            Flash::error('Choose a valid default destination.');
            return Response::redirect('/moh');
        }

        $upload = $this->upload($tid, $file);
        if (isset($upload['error'])) {
            Flash::error($upload['error']);
            return Response::redirect('/moh');
        }

        try {
            $id = $this->db()->insert('announcements', [
                'tenant_id' => $tid,
                'name' => $name,
                'filename' => $upload['filename'],
                'sound_path' => $upload['sound_path'],
                'dest_type' => $destination['type'],
                'dest_id' => $destination['id'],
                'enabled' => $request->bool('enabled', true) ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->deleteFile((string) $upload['filename'], $tid);
            throw $e;
        }
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
        $destination = $this->destination($request, $tid);
        if ($destination === null) {
            Flash::error('Choose a valid default destination.');
            return Response::redirect('/moh');
        }
        $data['dest_type'] = $destination['type'];
        $data['dest_id'] = $destination['id'];
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
        $this->db()->run(
            'UPDATE inbound_routes SET dest_type = "hangup", dest_id = NULL WHERE tenant_id = ? AND dest_type = "announcement" AND dest_id = ?',
            [$tid, (int) $row['id']]
        );
        $this->db()->run(
            'UPDATE time_conditions SET open_type = "hangup", open_dest_id = NULL WHERE tenant_id = ? AND open_type = "announcement" AND open_dest_id = ?',
            [$tid, (int) $row['id']]
        );
        $this->db()->run(
            'UPDATE time_conditions SET closed_type = "hangup", closed_dest_id = NULL WHERE tenant_id = ? AND closed_type = "announcement" AND closed_dest_id = ?',
            [$tid, (int) $row['id']]
        );
        foreach ($this->db()->all('SELECT id, keys, timeout_action, timeout_dest_id FROM ivr_menus WHERE tenant_id = ?', [$tid]) as $ivr) {
            $changes = [];
            $keys = json_decode((string) ($ivr['keys'] ?? ''), true) ?: [];
            foreach ($keys as $key => $destination) {
                if (($destination['type'] ?? '') === 'announcement' && (int) ($destination['id'] ?? 0) === (int) $row['id']) {
                    unset($keys[$key]);
                    $changes['keys'] = json_encode($keys, JSON_UNESCAPED_SLASHES);
                }
            }
            if (($ivr['timeout_action'] ?? '') === 'announcement' && (int) ($ivr['timeout_dest_id'] ?? 0) === (int) $row['id']) {
                $changes['timeout_action'] = null;
                $changes['timeout_dest_id'] = null;
            }
            if ($changes) { $this->db()->update('ivr_menus', (int) $ivr['id'], $changes); }
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
        if (!$file) {
            return ['error' => 'Choose an audio file to upload.'];
        }
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            return ['error' => $this->uploadErrorMessage($uploadError)];
        }
        if ((int) ($file['size'] ?? 0) > 25 * 1024 * 1024) {
            return ['error' => 'The announcement file is larger than 25 MB.'];
        }
        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            return ['error' => 'The announcement upload is invalid.'];
        }
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['wav', 'mp3', 'ogg', 'flac', 'gsm', 'ulaw', 'alaw', 'm4a', 'mp4', 'webm'], true)) {
            return ['error' => 'Use WAV, MP3, OGG, FLAC, GSM, ULAW, ALAW, M4A, MP4 or WebM audio.'];
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
        if (!is_writable($dir)) {
            return ['error' => 'The announcement directory is not writable. Ask the server administrator to correct its ownership.'];
        }
        $base = 'announcement_' . bin2hex(random_bytes(8));
        $filename = $base . '.wav';
        $target = $dir . '/' . $filename;
        if (!$this->convertAudio((string) $file['tmp_name'], $target)) {
            @unlink($target);
            return ['error' => 'Audio conversion failed. The file may be damaged or use an unsupported codec.'];
        }
        @chmod($target, 0640);
        return ['filename' => $filename, 'sound_path' => 'asterisk-panel/' . $context . '/' . $base];
    }

    private function convertAudio(string $source, string $target): bool
    {
        $commands = [
            'sox ' . escapeshellarg($source) . ' -r 8000 -c 1 -e signed-integer -b 16 ' . escapeshellarg($target) . ' 2>&1',
            'ffmpeg -nostdin -loglevel error -y -i ' . escapeshellarg($source)
                . ' -ar 8000 -ac 1 -c:a pcm_s16le ' . escapeshellarg($target) . ' 2>&1',
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

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The announcement exceeds the server upload limit.',
            UPLOAD_ERR_PARTIAL => 'The announcement upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'Choose an audio file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the announcement file.',
            UPLOAD_ERR_EXTENSION => 'A server extension stopped the announcement upload.',
            default => 'The announcement upload failed.',
        };
    }

    /** @return array{type:string,id:?int}|null */
    private function destination(Request $request, int $tenantId): ?array
    {
        $type = $request->str('dest_type', 'hangup');
        if ($type === 'hangup') { return ['type' => 'hangup', 'id' => null]; }
        $table = self::DEST_TABLES[$type] ?? null;
        $id = $request->int('dest_id');
        if (!$table || $id <= 0) { return null; }
        $exists = $this->db()->scalar(
            "SELECT COUNT(*) FROM {$table} WHERE id = ? AND tenant_id = ? AND enabled = 1",
            [$id, $tenantId]
        );
        return $exists ? ['type' => $type, 'id' => $id] : null;
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
