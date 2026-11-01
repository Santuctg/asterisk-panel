<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Config;
use App\Core\Logger;

/**
 * Sends mail via PHP's mail() or an SMTP relay.
 *
 * For voicemail-to-email, Asterisk itself calls out through `mailcmd`
 * (see resources/config/asterisk.php + scripts/asterisk-mail.php), so this
 * service is used for the panel's own notifications (e.g. new user created,
 * device provisioned) where we already have the app in the loop.
 */
final class Mailer
{
    /** @var array{from:string,from_name:string,smtp_host?:string,smtp_port?:int,smtp_user?:string,smtp_pass?:string} */
    private array $cfg;

    public function __construct()
    {
        $this->cfg = Config::get('mail', []);
    }

    public function to(string $to, string $subject, string $body, array $headers = []): bool
    {
        $from     = $this->cfg['from'] ?? 'no-reply@localhost';
        $fromName = $this->cfg['from_name'] ?? 'PBX Admin';

        $hdr  = 'From: ' . sprintf('%s <%s>', $fromName, $from) . "\r\n";
        $hdr .= 'Reply-To: ' . $from . "\r\n";
        $hdr .= 'MIME-Version: 1.0' . "\r\n";
        $hdr .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";

        foreach ($headers as $k => $v) {
            $hdr .= $k . ': ' . $v . "\r\n";
        }

        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $hdr);

        if (!$ok) {
            Logger::error('Failed to send email', ['to' => $to, 'subject' => $subject]);
        }

        return $ok;
    }
}
