<?php

declare(strict_types=1);

/**
 * Outbound mail (panel notifications). Voicemail-to-email itself is sent by
 * Asterisk via `mailcmd` in voicemail.conf — see scripts/asterisk-mail.php.
 */

$env = static fn (string $key, mixed $default = null): mixed => getenv($key) !== false ? getenv($key) : $default;

return [
    'from'      => $env('MAIL_FROM', 'no-reply@pbx.local'),
    'from_name' => $env('MAIL_FROM_NAME', 'Asterisk Panel'),
    'smtp_host' => $env('MAIL_SMTP_HOST', ''),
    'smtp_port' => (int) $env('MAIL_SMTP_PORT', 587),
    'smtp_user' => $env('MAIL_SMTP_USER', ''),
    'smtp_pass' => $env('MAIL_SMTP_PASS', ''),
];
