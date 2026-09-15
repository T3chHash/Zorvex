<?php

declare(strict_types=1);

namespace Zorvex\Core;

/**
 * Immutable HTTP response builder / emitter.
 *
 * @package Zorvex\Core
 */
final class Response
{
    private const STATUS_TEXT = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    private readonly int $status;

    private readonly array $headers;

    private readonly string $content;

    public function __construct(int $status = 200, string $content = '', array $headers = [])
    {
        $this->status = $status;
        $this->content = $content;
        $this->headers = $headers;
    }

    public static function json(mixed $payload, int $status = 200, array $headers = []): self
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            $json = json_encode(['ok' => false, 'error' => 'response encoding failed']);
            $status = 500;
        }

        return new self($status, $json, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function html(string $html, int $status = 200, array $headers = []): self
    {
        return new self($status, $html, $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, '', ['Location' => $url]);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($status, $text, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Send the response to the client and terminate the script.
     */
    public function send(): never
    {
        http_response_code($this->status);

        $reason = self::STATUS_TEXT[$this->status] ?? 'Unknown';
        header(sprintf('HTTP/1.1 %d %s', $this->status, $reason), true, $this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->content;
        exit;
    }
}