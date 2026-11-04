<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

final class UpdateService
{
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
}
