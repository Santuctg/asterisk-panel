<?php

declare(strict_types=1);

/**
 * Asterisk integration settings.
 *
 * AMI (manager.conf) is how the panel talks to a live Asterisk for status
 * and reloads. `config_dir` is where generated conf files are written; a
 * matching #include must exist in the main Asterisk conf files — see
 * docs/asterisk-setup.md.
 */

$env = static fn (string $key, mixed $default = null): mixed => getenv($key) !== false ? getenv($key) : $default;

return [
    'ami' => [
        'host'     => $env('AMI_HOST', '127.0.0.1'),
        'port'     => (int) $env('AMI_PORT', 5038),
        'username' => $env('AMI_USER', 'admin'),
        'secret'   => $env('AMI_SECRET', 'change-me'),
        'timeout'  => (int) $env('AMI_TIMEOUT', 8),
    ],

    // Where ConfigGenerator writes its output. On the Asterisk box this is
    // usually /etc/asterisk/asterisk_panel.
    'config_dir' => $env('ASTERISK_CONFIG_DIR', '/etc/asterisk/asterisk_panel'),

    // Hostname/IP phones use to register (the "outbound proxy"/registrar).
    'sip_host'   => $env('ASTERISK_SIP_HOST', 'pbx.example.com'),
    'sip_port'   => (int) $env('ASTERISK_SIP_PORT', 5060),
    'time_server'=> $env('ASTERISK_NTP', 'pool.ntp.org'),

    // Root of music-on-hold class directories.
    'moh_path'   => $env('ASTERISK_MOH_PATH', '/var/lib/asterisk/moh'),
    'announcement_dir' => $env('ASTERISK_ANNOUNCEMENT_DIR', '/var/lib/asterisk/sounds/asterisk-panel'),
    'recording_dir' => $env('ASTERISK_RECORDING_DIR', '/var/spool/asterisk/monitor/asterisk-panel'),

    'voicemail' => [
        'serveremail' => $env('VM_SERVEREMAIL', 'asterisk@localhost'),
        'attach'      => $env('VM_ATTACH', 'yes'),
        'mailcmd'     => $env('VM_MAILCMD', '/usr/bin/php ' . BASE_PATH . '/scripts/asterisk-mail.php'),
    ],
];
