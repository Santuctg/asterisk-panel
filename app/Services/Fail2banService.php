<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

final class Fail2banService
{
    public const JAILS = ['asterisk', 'asterisk-panel-login'];

    public function status(): array
    {
        [$code, $output] = $this->run(['status']);
        if ($code !== 0) { throw new \RuntimeException('Fail2ban status helper failed: ' . trim($output)); }
        return self::parseStatus($output);
    }

    public function action(string $action, string $jail, string $ip): void
    {
        if (!in_array($action, ['ban', 'unban'], true) || !in_array($jail, self::JAILS, true)) {
            throw new \RuntimeException('Invalid Fail2ban action or jail.');
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) { throw new \RuntimeException('Enter a valid IPv4 or IPv6 address.'); }
        [$code, $output] = $this->run([$action, $jail, $ip]);
        if ($code !== 0) { throw new \RuntimeException('Fail2ban action failed: ' . trim($output)); }
    }

    public static function parseStatus(string $output): array
    {
        $result = [];
        $blocks = preg_split('/^__JAIL__:/m', $output) ?: [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') { continue; }
            [$jail, $body] = array_pad(explode("\n", $block, 2), 2, '');
            $row = ['jail'=>trim($jail), 'currently_failed'=>0, 'total_failed'=>0, 'currently_banned'=>0, 'total_banned'=>0, 'banned_ips'=>[]];
            foreach (['Currently failed'=>'currently_failed','Total failed'=>'total_failed','Currently banned'=>'currently_banned','Total banned'=>'total_banned'] as $label=>$key) {
                if (preg_match('/'.preg_quote($label,'/').':\s*(\d+)/i', $body, $m)) { $row[$key]=(int)$m[1]; }
            }
            if (preg_match('/Banned IP list:\s*([^\r\n]*)/i', $body, $m)) {
                $row['banned_ips'] = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $result[$row['jail']] = $row;
        }
        return $result;
    }

    private function run(array $args): array
    {
        $sudo=(string)Config::get('security.sudo','/usr/bin/sudo');
        $helper=(string)Config::get('security.helper','/usr/local/sbin/asterisk-panel-security');
        if (!function_exists('proc_open') || !is_executable($helper)) { throw new \RuntimeException('Fail2ban helper is not installed.'); }
        $process=proc_open(array_merge([$sudo,'-n',$helper],$args),[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['redirect',1]],$pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Unable to run Fail2ban helper.'); }
        $output=(string)stream_get_contents($pipes[1]); fclose($pipes[1]);
        return [proc_close($process),$output];
    }
}
