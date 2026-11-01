<?php

declare(strict_types=1);

namespace App\Services\Asterisk;

use App\Core\Config;
use App\Core\Logger;

/**
 * Minimal Asterisk Manager Interface (AMI) client.
 *
 * Speaks the classic line protocol over TCP (default 127.0.0.1:5038):
 *   - send  : "Action: <name>\r\nHeader: value\r\n\r\n"
 *   - recv  : blocks of "Header: value" lines ended by a blank line
 *
 * Used for runtime control: `core show version`, `pjsip show contacts`,
 * `queue show`, `originate`, module reloads, etc. Configuration changes are
 * written to disk (see ConfigGenerator) and then applied with a reload action.
 */
final class AmiClient
{
    private $socket = null;
    private bool $connected = false;
    private bool $loggedIn = false;
    private bool $connectFailed = false;

    private string $host;
    private int $port;
    private string $user;
    private string $secret;
    private int $timeout;

    public function __construct()
    {
        $this->host    = (string) Config::get('asterisk.ami.host', '127.0.0.1');
        $this->port    = (int) Config::get('asterisk.ami.port', 5038);
        $this->user    = (string) Config::get('asterisk.ami.username', 'admin');
        $this->secret  = (string) Config::get('asterisk.ami.secret', 'secret');
        $this->timeout = (int) Config::get('asterisk.ami.timeout', 8);
    }

    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        // A refused/failed connect is memoized so callers that issue several
        // actions in a row (e.g. reloadManaged) don't pay the TCP timeout
        // seven times over.
        if ($this->connectFailed) {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);

        if (!$sock) {
            $this->connectFailed = true;
            Logger::warning('AMI connection failed', ['host' => $this->host, 'port' => $this->port, 'error' => $errstr]);
            return false;
        }

        stream_set_timeout($sock, $this->timeout);
        $this->socket   = $sock;
        $this->connected = true;

        return $this->login();
    }

    private function login(): bool
    {
        $response = $this->transact([
            'Action' => 'Login',
            'Username' => $this->user,
            'Secret' => $this->secret,
            'Events' => 'off',
        ]);

        $this->loggedIn = stripos((string) ($response['Message'] ?? ''), 'success') !== false
            || (($response['Response'] ?? '') === 'Success');

        if (!$this->loggedIn) {
            Logger::warning('AMI login rejected', ['user' => $this->user]);
            $this->disconnect();
        }

        return $this->loggedIn;
    }

    /** Raw send of one action and read of its reply headers. */
    private function transact(array $headers): array
    {
        if (!$this->connected || !$this->socket) {
            return ['Response' => 'Error', 'Message' => 'Not connected'];
        }

        $payload = '';
        foreach ($headers as $k => $v) {
            $payload .= $k . ': ' . str_replace(["\r", "\n"], '', (string) $v) . "\r\n";
        }
        $payload .= "\r\n";

        if (@fwrite($this->socket, $payload) === false) {
            $this->disconnect();
            return ['Response' => 'Error', 'Message' => 'Write failed'];
        }

        return $this->readPacket();
    }

    /** Read headers until the blank line that terminates a packet. */
    private function readPacket(): array
    {
        $headers = [];
        $guard  = 0;

        while (($line = fgets($this->socket)) !== false) {
            $guard++;
            if ($guard > 2000) {
                break;
            }

            $line = rtrim($line, "\r\n");

            if ($line === '') {
                if ($headers) {
                    break;
                }
                continue; // leading blank line
            }

            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }

            $name  = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Multi-valued headers (e.g. repeated "Output" lines) are appended.
            if (isset($headers[$name])) {
                $headers[$name] .= "\n" . $value;
            } else {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    /** Send an action; returns the reply headers (plus Output/Event payloads). */
    public function action(string $name, array $headers = []): array
    {
        if (!$this->connect()) {
            return ['Response' => 'Error', 'Message' => 'Cannot connect to Asterisk AMI'];
        }

        return $this->transact(array_merge(['Action' => $name], $headers));
    }

    /**
     * Run a CLI command via AMI and return its stdout.
     * e.g. command('core show version') -> "Asterisk 22.0.0 built by ... "
     */
    public function command(string $cli): string
    {
        $resp = $this->action('Command', ['Command' => $cli]);
        return (string) ($resp['Output'] ?? $resp['Message'] ?? '');
    }

    /** Originate a call (used by "call now" / test buttons). */
    public function originate(string $channel, string $context, string $exten, string $priority = '1', array $vars = [], string $callerId = ''): array
    {
        $headers = [
            'Channel'   => $channel,
            'Context'   => $context,
            'Exten'     => $exten,
            'Priority'  => $priority,
            'Async'     => 'true',
            'Timeout'   => 30000,
            'Variable'  => implode(',', array_map(
                static fn ($k, $v) => $k . '=' . $v,
                array_keys($vars),
                array_values($vars)
            )),
        ];

        if ($callerId !== '') {
            $headers['CallerID'] = $callerId;
        }

        return $this->action('Originate', $headers);
    }

    /** Queue a list of CLI commands, returning [command => output]. */
    public function commands(array $clis): array
    {
        $out = [];
        foreach ($clis as $cli) {
            $out[$cli] = $this->command($cli);
        }
        return $out;
    }

    /** Fire-and-forget reload of a module/config set. */
    public function reload(string $what = ''): array
    {
        return $what === ''
            ? $this->action('Command', ['Command' => 'core reload'])
            : $this->action('Command', ['Command' => 'module reload ' . $what]);
    }

    public function isConnected(): bool
    {
        return $this->connected && $this->loggedIn;
    }

    public function disconnect(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
        }
        $this->socket    = null;
        $this->connected = false;
        $this->loggedIn  = false;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
