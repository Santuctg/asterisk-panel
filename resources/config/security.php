<?php
declare(strict_types=1);
$env = static fn(string $key, mixed $default=null): mixed => getenv($key)!==false ? getenv($key) : $default;
return [
    'sudo' => $env('SECURITY_SUDO_BIN', '/usr/bin/sudo'),
    'helper' => $env('SECURITY_HELPER_BIN', '/usr/local/sbin/asterisk-panel-security'),
];
