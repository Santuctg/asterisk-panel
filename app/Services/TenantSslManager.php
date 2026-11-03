<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Config;

/** ACME-DNS certificate lifecycle for one tenant-owned hostname. */
final class TenantSslManager
{
    private array $tenant;
    private string $stateFile;
    private string $apiBase;

    public function __construct(private int $tenantId)
    {
        $tenant = App::getInstance()->db()->one('SELECT * FROM tenants WHERE id = ?', [$tenantId]);
        if (!$tenant) {
            throw new \RuntimeException('Tenant not found.');
        }
        $this->tenant = $tenant;
        $this->stateFile = BASE_PATH . '/storage/acme/tenant-' . $tenantId . '.json';
        $this->apiBase = rtrim((string) Config::get('ssl.acme_dns_url', 'https://auth.acme-dns.io'), '/');
    }

    public function publicState(): array
    {
        $state = $this->readState();
        unset($state['username'], $state['password'], $state['subdomain']);
        $state['tenant'] = $this->tenant;
        $state['helper_ready'] = is_executable((string) Config::get('ssl.helper', '/usr/local/sbin/asterisk-panel-ssl'));
        return $state;
    }

    public function register(): void
    {
        $domain = strtolower(rtrim(trim((string) $this->tenant['domain']), '.'));
        $email = strtolower(trim((string) $this->tenant['contact_email']));
        $this->assertDomain($domain);
        $panelDomain = strtolower((string) parse_url((string) Config::get('app.url', ''), PHP_URL_HOST));
        if ($panelDomain !== '' && hash_equals($panelDomain, $domain)) {
            throw new \RuntimeException('The tenant domain must be different from the main panel domain.');
        }
        $duplicate = App::getInstance()->db()->scalar(
            'SELECT COUNT(*) FROM tenants WHERE id <> ? AND LOWER(domain) = ?', [$this->tenantId, $domain]
        );
        if ($duplicate) { throw new \RuntimeException('This domain is already assigned to another tenant.'); }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Set a valid tenant contact email before registering SSL.');
        }
        $current = $this->readState();
        if (($current['domain'] ?? '') === $domain && !empty($current['username'])) {
            return;
        }
        if ($current) {
            throw new \RuntimeException('Reset the existing tenant SSL registration before changing its domain.');
        }
        $response = $this->postJson($this->apiBase . '/register');
        foreach (['username', 'password', 'subdomain', 'fulldomain'] as $key) {
            if (empty($response[$key]) || !is_string($response[$key])) {
                throw new \RuntimeException('The hosted ACME-DNS service returned an incomplete registration.');
            }
        }
        $this->writeState([
            'tenant_id' => $this->tenantId, 'domain' => $domain, 'email' => $email,
            'api_base' => $this->apiBase, 'username' => $response['username'],
            'password' => $response['password'], 'subdomain' => $response['subdomain'],
            'fulldomain' => rtrim($response['fulldomain'], '.'),
            'cname_name' => '_acme-challenge.' . $domain,
            'status' => 'registered', 'registered_at' => date(DATE_ATOM),
        ]);
    }

    public function verify(): bool
    {
        $state = $this->readState();
        if (empty($state['cname_name']) || empty($state['fulldomain'])) {
            throw new \RuntimeException('Register this tenant with ACME-DNS first.');
        }
        $expected = strtolower(rtrim((string) $state['fulldomain'], '.'));
        $verified = false;
        foreach (@dns_get_record((string) $state['cname_name'], DNS_CNAME) ?: [] as $record) {
            if (hash_equals($expected, strtolower(rtrim((string) ($record['target'] ?? ''), '.')))) {
                $verified = true; break;
            }
        }
        $state['cname_verified'] = $verified;
        $state['cname_checked_at'] = date(DATE_ATOM);
        if ($verified) { $state['status'] = 'dns_ready'; }
        $this->writeState($state);
        return $verified;
    }

    public function issue(): void
    {
        if (!$this->verify()) { throw new \RuntimeException('The tenant CNAME is not visible in public DNS.'); }
        if (!function_exists('proc_open')) { throw new \RuntimeException('PHP proc_open is disabled.'); }
        $sudo = (string) Config::get('ssl.sudo', '/usr/bin/sudo');
        $helper = (string) Config::get('ssl.helper', '/usr/local/sbin/asterisk-panel-ssl');
        $process = proc_open([$sudo, '-n', $helper, 'issue-tenant', (string) $this->tenantId],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) { throw new \RuntimeException('Unable to start the tenant SSL helper.'); }
        set_time_limit(600);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]); $exit = proc_close($process);
        $state = $this->readState();
        $state['last_attempt_at'] = date(DATE_ATOM);
        $state['last_output'] = mb_substr(trim((string) $output), -8000);
        $state['status'] = $exit === 0 ? 'active' : 'error';
        if ($exit === 0) { $state['issued_at'] = date(DATE_ATOM); }
        $this->writeState($state);
        if ($exit !== 0) { throw new \RuntimeException("Tenant SSL helper failed (status {$exit}). Check its output."); }
    }

    public function reset(): void
    {
        if (is_file($this->stateFile) && !unlink($this->stateFile)) {
            throw new \RuntimeException('Unable to clear tenant SSL registration.');
        }
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) { return []; }
        $decoded = json_decode((string) file_get_contents($this->stateFile), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) { mkdir($dir, 0700, true); }
        $tmp = $this->stateFile . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL, LOCK_EX);
        chmod($tmp, 0600);
        if (!rename($tmp, $this->stateFile)) { @unlink($tmp); throw new \RuntimeException('Unable to save tenant SSL state.'); }
    }

    private function postJson(string $url): array
    {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n", 'content' => '{}', 'timeout' => 20, 'ignore_errors' => true], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $ctx); $status = 0;
        foreach ($http_response_header ?? [] as $header) { if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $m)) { $status = (int) $m[1]; } }
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($decoded)) { throw new \RuntimeException('Hosted ACME-DNS registration failed.'); }
        return $decoded;
    }

    private function assertDomain(string $domain): void
    {
        if (!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new \RuntimeException('Set a valid public tenant hostname first.');
        }
    }
}
