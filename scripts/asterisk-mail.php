<?php

declare(strict_types=1);

/**
 * Voicemail-to-email mail relay.
 *
 * Asterisk's app_voicemail hands off to an external command via the
 * `mailcmd` setting in voicemail.conf. It invokes that command with the
 * full RFC-822 message on STDIN (including a multipart MIME body with the
 * audio attachment when "attach" is on).
 *
 * Wire it up in your Asterisk voicemail.conf [general] section:
 *
 *     mailcmd = /usr/bin/php /opt/asterisk-panel/scripts/asterisk-mail.php
 *
 * This script simply relays STDIN to the system's MTA (sendmail/postfix) or
 * an SMTP relay, so Asterisk never needs direct SMTP credentials.
 *
 * It also accepts a direct invocation for testing:
 *
 *     echo "Subject: test" | php scripts/asterisk-mail.php
 */

// If run via CLI but nothing is on STDIN, exit quietly (misconfiguration
// guard so we never block the Asterisk channel with a prompt).
if (PHP_SAPI === 'cli' && posix_isatty(STDIN)) {
    fwrite(STDERR, "asterisk-mail.php: expected an RFC-822 message on STDIN.\n");
    exit(1);
}

$mailRaw = stream_get_contents(STDIN);

if ($mailRaw === false || trim($mailRaw) === '') {
    // Nothing to send; succeed silently so Asterisk doesn't retry forever.
    exit(0);
}

// Optional: append tenant/panel branding to the subject or body by reading
// environment/config. Kept intentionally simple — Asterisk already filled in
// the subject via its own templates.

// --- Method 1: hand off to the local MTA (sendmail/postfix/exim) ---------
$sendmail = getenv('SENDMAIL_PATH') ?: '/usr/sbin/sendmail';

if (is_executable($sendmail)) {
    $cmd = escapeshellarg($sendmail) . ' -t -i';
    $proc = proc_open($cmd, [0 => ['pipe', 'r']], $pipes);

    if (is_resource($proc)) {
        fwrite($pipes[0], $mailRaw);
        fclose($pipes[0]);
        proc_close($proc);
        exit(0);
    }
}

// --- Method 2: minimal SMTP relay ---------------------------------------
// Used when no local MTA is present. Configure via environment:
//   VM_SMTP_HOST=smtp.example.com
//   VM_SMTP_PORT=587
//   VM_SMTP_USER=user     (optional)
//   VM_SMTP_PASS=pass     (optional)
//   VM_SMTP_ENCRYPTION=tls|ssl|none   (optional, default tls)
$host = getenv('VM_SMTP_HOST');

if (!$host) {
    // No transport available. Log and succeed so we don't block voicemail.
    error_log('asterisk-mail.php: no sendmail binary and VM_SMTP_HOST not set; message dropped.');
    exit(0);
}

$port = (int) (getenv('VM_SMTP_PORT') ?: 587);
$user = getenv('VM_SMTP_USER') ?: '';
$pass = getenv('VM_SMTP_PASS') ?: '';
$enc  = getenv('VM_SMTP_ENCRYPTION') ?: 'tls';

$remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
$fp = @stream_socket_client($remote, $errno, $errstr, 20);

if (!$fp) {
    error_log("asterisk-mail.php: SMTP connect failed: {$errstr} ({$errno})");
    exit(0); // Don't fail the call for a mail problem.
}

stream_set_timeout($fp, 20);

$read = static function () use ($fp): string {
    $data = '';
    while ($line = fgets($fp, 515)) {
        $data .= $line;
        if (substr($line, 3, 1) === ' ') {
            break;
        }
    }
    return $data;
};

$write = static function (string $cmd) use ($fp): void {
    fwrite($fp, $cmd . "\r\n");
};

$read(); // greeting

if ($enc === 'tls') {
    $write('STARTTLS');
    $read();
    stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
}

if ($user !== '') {
    $write('AUTH LOGIN');
    $read();
    $write(base64_encode($user));
    $read();
    $write(base64_encode($pass));
    $read();
}

// Extract envelope addresses from the message headers.
preg_match('/^From:\s*.*?<([^>]+)>/mi', $mailRaw, $mFrom);
preg_match('/^To:\s*.*?<([^>]+)>/mi', $mailRaw, $mTo);
$from = $mFrom[1] ?? ($user ?: 'asterisk@localhost');
$to   = $mTo[1]   ?? null;

if (!$to) {
    preg_match('/^To:\s*([^\r\n]+)/mi', $mailRaw, $mTo2);
    $to = trim($mTo2[1] ?? '');
}

if (!$to) {
    error_log('asterisk-mail.php: could not determine recipient; dropping.');
    exit(0);
}

$write("MAIL FROM:<{$from}>");
$read();
$write("RCPT TO:<{$to}>");
$read();
$write('DATA');
$read();
// Dot-stuff any line beginning with a period so it isn't treated as end-of-data.
$write(preg_replace('/^\./m', '..', $mailRaw));
$write('.');
$read();
$write('QUIT');

fclose($fp);
exit(0);
