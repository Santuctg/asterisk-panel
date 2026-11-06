<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

final class UpdateService
{
    private function jobFile(): string
    {
        return BASE_PATH . '/storage/update-job.json';
    }

    private function writeJob(array $job): void
    {
        $job['updated_at'] = date(DATE_ATOM);
        $file = $this->jobFile();
        @mkdir(dirname($file), 0775, true);
        $tmp = $file . '.tmp';
        if (file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false
            || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to save update progress.');
        }
    }

    public function jobStatus(): array
    {
        $file = $this->jobFile();
        if (!is_file($file)) return ['state' => 'idle', 'phase' => 'ready'];
        $job = json_decode((string) file_get_contents($file), true);
        if (!is_array($job)) return ['state' => 'idle', 'phase' => 'ready'];
        $updated = strtotime((string) ($job['updated_at'] ?? '')) ?: 0;
        if (in_array($job['state'] ?? '', ['queued', 'running'], true) && $updated < time() - 1800) {
            $job['state'] = 'failed'; $job['phase'] = 'error'; $job['message'] = 'The update job stopped responding.';
        }
        return $job;
    }

    private function run(string $action): array
    {
        $helper = (string) Config::get('security.update_helper', '/usr/local/sbin/asterisk-panel-update');
        $sudo = (string) Config::get('security.sudo', '/usr/bin/sudo');
        if (!is_executable($helper)) throw new \RuntimeException('Update helper is not installed.');
        $p = proc_open([$sudo, '-n', $helper, $action], [1 => ['pipe','w'], 2 => ['redirect',1]], $pipes);
        if (!is_resource($p)) throw new \RuntimeException('Unable to start update helper.');
        $output = (string) stream_get_contents($pipes[1]); fclose($pipes[1]);
        return [proc_close($p), $output];
    }

    public function status(): array
    {
        [$code, $output] = $this->run('status');
        if ($code !== 0) throw new \RuntimeException(trim($output));
        $data = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) if (str_contains($line, '=')) { [$k,$v] = explode('=', $line, 2); $data[$k] = trim($v); }
        return $data;
    }

    public function update(): string
    {
        [$code, $output] = $this->run('update');
        if ($code !== 0) throw new \RuntimeException(trim($output));
        return trim($output);
    }

    /** Launch the privileged updater outside the web request. */
    public function start(?int $userId = null): array
    {
        $existing = $this->jobStatus();
        $updated = strtotime((string) ($existing['updated_at'] ?? '')) ?: 0;
        if (in_array($existing['state'] ?? '', ['queued', 'running'], true) && $updated > time() - 1800) {
            return $existing;
        }

        $status = $this->status();
        $job = [
            'state' => 'queued', 'phase' => 'starting', 'message' => 'Starting update…',
            'current_version' => (string) ($status['current_version'] ?? ''),
            'target_version' => (string) ($status['latest_version'] ?? ''),
            'started_at' => date(DATE_ATOM), 'user_id' => $userId,
        ];
        $this->writeJob($job);

        $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
        $worker = BASE_PATH . '/scripts/run-update-job.php';
        $log = BASE_PATH . '/storage/logs/update-job.log';
        @mkdir(dirname($log), 0775, true);
        $command = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($worker)
            . ' > ' . escapeshellarg($log) . ' 2>&1 < /dev/null & echo $!';
        $process = proc_open(['/bin/sh', '-c', $command], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $job['state'] = 'failed'; $job['phase'] = 'error'; $job['message'] = 'Unable to start update worker.';
            $this->writeJob($job);
            throw new \RuntimeException($job['message']);
        }
        $pid = trim((string) stream_get_contents($pipes[1]));
        $error = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        if ($pid === '' || !ctype_digit($pid)) {
            $job['state'] = 'failed'; $job['phase'] = 'error'; $job['message'] = $error ?: 'Update worker did not start.';
            $this->writeJob($job);
            throw new \RuntimeException($job['message']);
        }
        $job['pid'] = (int) $pid;
        return $job;
    }

    /** CLI worker entry point; survives PHP-FPM and application reloads. */
    public function runJob(): void
    {
        $job = $this->jobStatus();
        $job['state'] = 'running'; $job['phase'] = 'install'; $job['message'] = 'Installing verified release…';
        $this->writeJob($job);
        try {
            $job['result'] = $this->update();
            $job['state'] = 'completed'; $job['phase'] = 'complete'; $job['message'] = 'Update completed successfully.';
            $job['completed_at'] = date(DATE_ATOM);
        } catch (\Throwable $e) {
            $job['state'] = 'failed'; $job['phase'] = 'error'; $job['message'] = $e->getMessage();
            $job['completed_at'] = date(DATE_ATOM);
        }
        $this->writeJob($job);
    }
}
