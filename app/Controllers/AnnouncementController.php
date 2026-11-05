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
        $hasUpload = $file && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $promptId = $request->int('prompt_id');
        $source = $promptId > 0 ? $this->db()->one(
            'SELECT * FROM announcements WHERE id = ? AND tenant_id = ?',
            [$promptId, $tid]
        ) : null;
        $name = $request->str('name');
        if ($name === '' && $hasUpload) {
            $name = trim((string) preg_replace(
                '/[_-]+/',
                ' ',
                pathinfo((string) ($file['name'] ?? ''), PATHINFO_FILENAME)
            ));
        } elseif ($name === '' && $source) {
            $original = trim((string) ($source['original_filename'] ?? ''));
            $name = $original !== '' ? pathinfo($original, PATHINFO_FILENAME) : (string) $source['name'];
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

        $upload = $hasUpload ? $this->upload($tid, $file) : ($source
            ? $this->copyPrompt($source, $tid)
            : ['error' => 'Choose an uploaded prompt or add a new audio file.']);
        if (isset($upload['error'])) {
            Flash::error($upload['error']);
            return Response::redirect('/moh');
        }

        try {
            $id = $this->db()->insert('announcements', [
                'tenant_id' => $tid,
                'name' => $name,
                'filename' => $upload['filename'],
                'original_filename' => $upload['original_filename'],
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
        $oldFilename = null;
        $file = $request->files('audio');
        if ($file && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upload = $this->upload($tid, $file);
            if (isset($upload['error'])) {
                Flash::error($upload['error']);
                return Response::redirect('/moh');
            }
            $data['filename'] = $upload['filename'];
            $data['original_filename'] = $upload['original_filename'];
            $data['sound_path'] = $upload['sound_path'];
            $oldFilename = (string) $row['filename'];
        } else {
            $promptId = $request->int('prompt_id');
            if ($promptId > 0 && $promptId !== (int) $row['id']) {
                $source = $this->db()->one(
                    'SELECT * FROM announcements WHERE id = ? AND tenant_id = ?',
                    [$promptId, $tid]
                );
                $copy = $source ? $this->copyPrompt($source, $tid) : ['error' => 'Choose a valid uploaded prompt.'];
                if (isset($copy['error'])) {
                    Flash::error($copy['error']);
                    return Response::redirect('/moh');
                }
                $data['filename'] = $copy['filename'];
                $data['original_filename'] = $copy['original_filename'];
                $data['sound_path'] = $copy['sound_path'];
                $oldFilename = (string) $row['filename'];
            }
        }
        $this->db()->update('announcements', (int) $row['id'], $data);
        if ($oldFilename !== null) { $this->deleteFile($oldFilename, $tid); }
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

    /** Authorize a tenant prompt, then let Nginx stream it with range support. */
    public function audio(Request $request): Response
    {
        $tid = $this->requireTenant();
        $row = $this->db()->one(
            'SELECT filename, original_filename, name FROM announcements WHERE id = ? AND tenant_id = ? AND enabled = 1',
            [(int) $request->params['id'], $tid]
        );
        if (!$row) { return Response::text('Audio not found.', 404); }
        $filename = (string) $row['filename'];
        if ($filename !== basename($filename) || !preg_match('/^[A-Za-z0-9_.-]+\.wav$/', $filename)) {
            return Response::text('Audio not found.', 404);
        }
        $tenant = $this->db()->one('SELECT context FROM tenants WHERE id = ?', [$tid]);
        if (!$tenant) { return Response::text('Audio not found.', 404); }
        $context = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $tenant['context']) ?: ('tenant_' . $tid);
        $root = rtrim((string) config('asterisk.announcement_dir', '/var/lib/asterisk/sounds/asterisk-panel'), '/');
        $path = $root . '/' . $context . '/' . $filename;
        if (!is_file($path) || !is_readable($path)) { return Response::text('Audio not found.', 404); }
        $disposition = $request->bool('download') ? 'attachment' : 'inline';
        $downloadName = basename(trim((string) ($row['original_filename'] ?? '')));
        if ($downloadName === '') { $downloadName = (string) $row['name'] . '.wav'; }
        $downloadName = preg_replace('/[\x00-\x1F\x7F"\\\\]+/', '_', $downloadName) ?: $filename;
        return new Response('', 200, [
            'Content-Type' => 'audio/wav',
            'Content-Disposition' => $disposition . '; filename="' . $downloadName . '"',
            'X-Accel-Redirect' => '/_protected/announcements/' . rawurlencode($context) . '/' . rawurlencode($filename),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array{filename:string,original_filename:string,sound_path:string}|array{error:string} */
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
        $original = basename(trim((string) ($file['name'] ?? '')));
        $original = preg_replace('/[\x00-\x1F\x7F]+/', '', $original) ?: $filename;
        return ['filename' => $filename, 'original_filename' => substr($original, 0, 255), 'sound_path' => 'asterisk-panel/' . $context . '/' . $base];
    }

    /** @return array{filename:string,original_filename:string,sound_path:string}|array{error:string} */
    private function copyPrompt(array $source, int $tenantId): array
    {
        $tenant = $this->db()->one('SELECT context FROM tenants WHERE id = ?', [$tenantId]);
        $sourceName = (string) ($source['filename'] ?? '');
        if (!$tenant || $sourceName !== basename($sourceName) || !preg_match('/^[A-Za-z0-9_.-]+\.wav$/', $sourceName)) {
            return ['error' => 'The selected prompt is invalid.'];
        }
        $context = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $tenant['context']) ?: ('tenant_' . $tenantId);
        $root = rtrim((string) config('asterisk.announcement_dir', '/var/lib/asterisk/sounds/asterisk-panel'), '/');
        $sourcePath = $root . '/' . $context . '/' . $sourceName;
        $base = 'announcement_' . bin2hex(random_bytes(8));
        $filename = $base . '.wav';
        if (!is_file($sourcePath) || !is_readable($sourcePath) || !@copy($sourcePath, $root . '/' . $context . '/' . $filename)) {
            return ['error' => 'The selected prompt file is unavailable.'];
        }
        @chmod($root . '/' . $context . '/' . $filename, 0640);
        $original = trim((string) ($source['original_filename'] ?? ''));
        if ($original === '') { $original = (string) ($source['name'] ?? 'Prompt') . '.wav'; }
        return ['filename' => $filename, 'original_filename' => $original, 'sound_path' => 'asterisk-panel/' . $context . '/' . $base];
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
