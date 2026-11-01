<?php

declare(strict_types=1);

$env = static fn (string $key, mixed $default = null): mixed => getenv($key) !== false ? getenv($key) : $default;

return [
    'acme_dns_url' => rtrim((string) $env('ACME_DNS_URL', 'https://auth.acme-dns.io'), '/'),
    'state_file'   => $env('SSL_STATE_FILE', dirname(__DIR__, 2) . '/storage/acme/acme-dns.json'),
    'sudo'         => $env('SSL_SUDO_BIN', '/usr/bin/sudo'),
    'helper'       => $env('SSL_HELPER_BIN', '/usr/local/sbin/asterisk-panel-ssl'),
];
