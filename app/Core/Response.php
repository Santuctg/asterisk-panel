<?php

declare(strict_types=1);

namespace App\Core;

/**
 * HTTP response + redirect helpers.
 */
final class Response
{
    /** Optional local file to stream instead of an in-memory body. */
    private ?string $filePath = null;

    public function __construct(
        private string $body = '',
        private int $status = 200,
        private array $headers = []
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            $status,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function xml(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to]);
    }

    /**
     * Stream a local file as the response body. Used when the web server
     * cannot hand the file off (e.g. Apache/cPanel has no X-Accel-Redirect),
     * so PHP serves the already-authorized file itself.
     */
    public static function file(string $path, array $headers = []): self
    {
        $response = new self('', 200, $headers);
        $response->filePath = $path;
        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->filePath !== null) {
            if (!isset($this->headers['Content-Length']) && is_file($this->filePath)) {
                header('Content-Length: ' . (string) filesize($this->filePath));
            }
            readfile($this->filePath);
            return;
        }
        echo $this->body;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }
}
