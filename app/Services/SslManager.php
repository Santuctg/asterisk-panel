<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/** Hosted acme-dns registration and access to the allow-listed root helper. */
final class SslManager
{
    private string $stateFile;
    private string $apiBase;

    public function __construct()
    {
        $this->stateFile = (string) Config::get('ssl.state_file', BASE_PATH . '/storage/acme/acme-dns.json');
        $this->apiBase = rtrim((string) Config::get('ssl.acme_dns_url', 'https://auth.acme-dns.io'), '/');
    }

    /** State safe to pass to a view; API credentials are deliberately removed. */
    public function publicState(): array
    {
        $state = $this->readState();
        $this->mergeInstalledCertificate($state);
        unset($state['username'], $state['password'], $state['subdomain']);
        $state['helper_ready'] = $this->helperReady();
        return $state;
    }

    /** Recognize certificates installed by the server installer too. */
    private function mergeInstalledCertificate(array &$state): void
    {
        $url = (string) Config::get('app.url', '');
        $domain = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($domain === '' || !preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,63}$/', $domain)) {
            return;
        }
        $cert = "/etc/letsencrypt/live/{$domain}/fullchain.pem";
        // www-data must not read the certificate/private-key pair. Nginx/root
        // owns them; existence is enough for the panel status indicator.
        if (!is_file($cert)) {
            return;
        }
        $state['certificate_present'] = true;
        $state['certificate_domain'] = $domain;
        $state['certificate_expiry'] ??= null;
        if (is_readable($cert) && function_exists('openssl_x509_parse')) {
            $parsed = @openssl_x509_parse((string) file_get_contents($cert));
            if (is_array($parsed) && !empty($parsed['validTo_time_t'])) {
                $state['certificate_expiry'] = date(DATE_ATOM, (int) $parsed['validTo_time_t']);
            }
        }
        if (empty($state['domain'])) {
            $state['domain'] = $domain;
        }
        if (empty($state['status']) || $state['status'] === 'not_configured') {
            $state['status'] = 'active';
        }
    }

    public function register(string $domain, string $email): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $this->assertDomain($domain);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('A valid certificate email address is required.');
        }
        if (!str_starts_with($this->apiBase, 'https://')) {
            throw new \RuntimeException('The ACME-DNS API must use HTTPS.');
        }

        $current = $this->readState();
        if (($current['domain'] ?? '') === $domain && !empty($current['username'])) {
            return $this->publicState();
        }
        if (!empty($current['domain']) && ($current['domain'] ?? '') !== $domain) {
            throw new \RuntimeException('A different domain is already registered. Reset it explicitly before registering a new hostname.');
        }

        $response = $this->postJson($this->apiBase . '/register', []);
        foreach (['username', 'password', 'subdomain', 'fulldomain'] as $key) {
            if (!isset($response[$key]) || !is_string($response[$key]) || $response[$key] === '') {
                throw new \RuntimeException('The hosted ACME-DNS service returned an incomplete registration.');
            }
        }

        $state = [
            'domain'       => $domain,
            'email'        => strtolower($email),
            'api_base'     => $this->apiBase,
            'username'     => $response['username'],
            'password'     => $response['password'],
            'subdomain'    => $response['subdomain'],
            'fulldomain'   => rtrim($response['fulldomain'], '.'),
            'cname_name'   => '_acme-challenge.' . $domain,
            'status'       => 'registered',
            'registered_at'=> date(DATE_ATOM),
        ];
        $this->writeState($state);
        return $this->publicState();
    }

    public function verifyCname(): bool
    {
        $state = $this->readState();
        if (empty($state['cname_name']) || empty($state['fulldomain'])) {
            throw new \RuntimeException('Register with Hosted ACME-DNS first.');
        }

        $expected = strtolower(rtrim((string) $state['fulldomain'], '.'));
        $records = @dns_get_record((string) $state['cname_name'], DNS_CNAME);
        $verified = false;
        foreach ($records ?: [] as $record) {
            $target = strtolower(rtrim((string) ($record['target'] ?? ''), '.'));
            if (hash_equals($expected, $target)) {
                $verified = true;
                break;
            }
        }

        $state['cname_verified'] = $verified;
        $state['cname_checked_at'] = date(DATE_ATOM);
        if ($verified && ($state['status'] ?? '') === 'registered') {
            $state['status'] = 'dns_ready';
        }
        $this->writeState($state);
        return $verified;
    }

    /** Issue/install the certificate through the narrowly scoped sudo helper. */
    public function issue(): array
    {
        if (!$this->verifyCname()) {
            throw new \RuntimeException('The required CNAME is not visible in public DNS yet.');
        }
        if (!$this->helperReady()) {
            throw new \RuntimeException('The privileged SSL helper is not installed. Run scripts/install-web-ssl-helper.sh once as root.');
        }
        if (!function_exists('proc_open')) {
            throw new \RuntimeException('PHP proc_open is disabled; the SSL helper cannot run.');
        }

        $sudo = (string) Config::get('ssl.sudo', '/usr/bin/sudo');
        $helper = (string) Config::get('ssl.helper', '/usr/local/sbin/asterisk-panel-ssl');
        $pipes = [];
        $process = proc_open(
            [$sudo, '-n', $helper, 'issue'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start the SSL helper.');
        }

        set_time_limit(600);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        $state = $this->readState();
        $state['last_attempt_at'] = date(DATE_ATOM);
        $state['last_output'] = mb_substr(trim((string) $output), -8000);
        if ($exit === 0) {
            $state['status'] = 'active';
            $state['issued_at'] = date(DATE_ATOM);
            $state['last_error'] = null;
        } else {
            $state['status'] = 'error';
            $state['last_error'] = 'SSL helper exited with status ' . $exit . '.';
        }
        $this->writeState($state);

        if ($exit !== 0) {
            throw new \RuntimeException($state['last_error'] . ' Check the output shown on the SSL page.');
        }
        return $this->publicState();
    }

    public function reset(): void
    {
        if (is_file($this->stateFile) && !unlink($this->stateFile)) {
            throw new \RuntimeException('Unable to remove the ACME-DNS registration file.');
        }
    }

    private function helperReady(): bool
    {
        $sudo = (string) Config::get('ssl.sudo', '/usr/bin/sudo');
        $helper = (string) Config::get('ssl.helper', '/usr/local/sbin/asterisk-panel-ssl');
        return is_executable($sudo) && is_executable($helper);
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($this->stateFile), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create secure ACME storage.');
        }
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $tmp = $this->stateFile . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to save ACME-DNS registration.');
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $this->stateFile)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to activate the ACME-DNS registration file.');
        }
    }

    private function postJson(string $url, array $payload): array
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content'       => json_encode($payload, JSON_THROW_ON_ERROR),
                'timeout'       => 20,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) {
                $status = (int) $m[1];
            }
        }
        if ($body === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('Hosted ACME-DNS registration failed (HTTP ' . ($status ?: 'network error') . ').');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Hosted ACME-DNS returned invalid JSON.');
        }
        return $decoded;
    }

    private function assertDomain(string $domain): void
    {
        if (strlen($domain) > 253
            || !str_contains($domain, '.')
            || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new \RuntimeException('Enter a valid public hostname, for example pbx.example.com.');
        }
    }
}
