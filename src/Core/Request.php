<?php

declare(strict_types=1);

namespace Zorvex\Core;

/**
 * Immutable HTTP / Telegram request parser.
 *
 * Provides typed access to the current request input, headers, and the raw
 * Telegram update payload.
 *
 * @package Zorvex\Core
 */
final class Request
{
    private array $get;

    private array $post;

    private array $server;

    private array $files;

    private array $cookies;

    /** @var array<string, mixed>|null Decoded JSON body (Telegram updates). */
    private ?array $json = null;

    /** @var array<string, mixed>|null Decoded web_app_init_data. */
    private ?array $webAppData = null;

    public function __construct(array $get = [], array $post = [], array $server = [], array $files = [], array $cookies = [])
    {
        $this->get = $get;
        $this->post = $post;
        $this->server = $server;
        $this->files = $files;
        $this->cookies = $cookies;
    }

    /**
     * Build a Request from PHP superglobals.
     */
    public static function capture(): self
    {
        return new self($_GET, $_POST, $_SERVER, $_FILES, $_COOKIE);
    }

    /**
     * Build a fresh Request from an explicit payload (testing / workers).
     *
     * @param array<string, mixed> $payload Telegram update array.
     */
    public static function fromArray(array $payload): self
    {
        $request = new self([], [], [], [], []);
        $request->json = $payload;

        return $request;
    }

    /**
     * The raw Telegram update payload (or empty array when not applicable).
     *
     * @return array<string, mixed>
     */
    public function update(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        return $this->body();
    }

    /**
     * Decoded JSON request body.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        $raw = $this->rawBody();
        if ($raw === '') {
            return $this->post;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $this->post;
    }

    /**
     * Raw request body string.
     */
    public function rawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    public function method(): string
    {
        return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = $this->server['REQUEST_URI'] ?? '/';
        $path = parse_url((string) $uri, PHP_URL_PATH);

        return $path === false || $path === null ? '/' : $path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->get[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }

        if (array_key_exists($key, $this->get)) {
            return $this->get[$key];
        }

        $body = $this->body();
        if (is_array($body) && array_key_exists($key, $body)) {
            return $body[$key];
        }

        return $default;
    }

    /**
     * A string query/body field with strict type safety.
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function file(string $key): ?array
    {
        return isset($this->files[$key]) && is_array($this->files[$key]) ? $this->files[$key] : null;
    }

    public function header(string $name, string $default = ''): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return (string) ($this->server[$key] ?? $default);
    }

    public function ip(): string
    {
        $candidates = [
            $this->server['HTTP_CF_CONNECTING_IP'] ?? null,
            $this->server['HTTP_X_FORWARDED_FOR'] ?? null,
            $this->server['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }

    public function isJson(): bool
    {
        return str_contains($this->header('Content-Type', ''), 'application/json');
    }

    public function isTelegramUpdate(): bool
    {
        $update = $this->update();
        if ($update === []) {
            return false;
        }

        return isset($update['update_id']) || isset($update['message']) || isset($update['callback_query'])
            || isset($update['my_chat_member']) || isset($update['pre_checkout_query'])
            || isset($update['successful_payment']);
    }

    /**
     * Decode and validate Telegram web_app init data against the bot token.
     *
     * Implements the documented HMAC-SHA256 verifier so the mini-app can trust
     * user identity.
     *
     * @return array<string, mixed>|null Decoded fields when valid, null when invalid.
     */
    public function webAppData(): ?array
    {
        if ($this->webAppData !== null) {
            return $this->webAppData;
        }

        $token = (string) config('telegram.token', '');
        $raw = (string) $this->header('X-Telegram-Init-Data', '');

        if ($raw === '' || $token === '') {
            return null;
        }

        $pairs = [];
        foreach (explode('&', $raw) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $pairs[$parts[0]] = urldecode($parts[1]);
            }
        }

        $hash = $pairs['hash'] ?? null;
        unset($pairs['hash']);

        if ($hash === null) {
            return null;
        }

        ksort($pairs);
        $payload = implode("\n", array_map(
            static fn (string $k, string $v): string => "{$k}={$v}",
            array_keys($pairs),
            $pairs
        ));

        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        $computed = bin2hex(hash_hmac('sha256', $payload, $secret, true));

        if (!hash_equals($computed, $hash)) {
            return null;
        }

        // Reject init data older than 24 hours.
        $auth = isset($pairs['auth_date']) ? (int) $pairs['auth_date'] : 0;
        if ($auth > 0 && (time() - $auth) > 86400) {
            return null;
        }

        $user = isset($pairs['user']) ? json_decode($pairs['user'], true) : null;
        if (is_array($user)) {
            $pairs['user'] = $user;
        }

        $this->webAppData = $pairs;

        return $pairs;
    }

    /**
     * The authenticated Telegram user from web app init data (mini-app only).
     *
     * @return array<string, mixed>|null
     */
    public function webAppUser(): ?array
    {
        $data = $this->webAppData();

        return isset($data['user']) && is_array($data['user']) ? $data['user'] : null;
    }
}