<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP request abstraction.
 */
final class Request
{
    public readonly string $method;
    public readonly string $path;

    /** @var array<string, mixed> */
    public array $params = []; // route params, e.g. ['id' => 42]

    public function __construct(
        ?string $method = null,
        ?string $path = null,
        private array $query = [],
        private array $post = [],
        private array $server = [],
        private array $files = [],
        private array $cookies = []
    ) {
        $this->method = strtoupper($method ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri          = $path ?? ($_SERVER['REQUEST_URI'] ?? '/');
        $this->path   = '/' . trim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');

        if (!$this->query) {
            $this->query = $_GET;
        }
        if (!$this->post) {
            $this->post = $_POST;
        }
        if (!$this->server) {
            $this->server = $_SERVER;
        }
        if (!$this->files) {
            $this->files = $_FILES;
        }
        if (!$this->cookies) {
            $this->cookies = $_COOKIE;
        }
    }

    public static function capture(): self
    {
        return new self();
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->input($key, null);
        if ($v === null) {
            return $default;
        }
        return in_array($v, [1, '1', true, 'true', 'on', 'yes'], true);
    }

    /** Get a value checking POST then query then route params. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $this->params[$key] ?? $default;
    }

    public function page(): int
    {
        return max(1, (int) ($this->query['page'] ?? 1));
    }

    public function perPage(): int
    {
        return min(200, max(5, (int) ($this->query['per'] ?? 25)));
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $this->server[$key] ?? $default;
    }

    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    public function wantsJson(): bool
    {
        return str_contains($this->header('Accept', ''), 'application/json') || $this->isAjax();
    }

    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    public function files(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        return is_array($f) ? $f : null;
    }
}
