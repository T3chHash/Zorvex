<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram;

/**
 * Per-chat command rate limiter (application-level, in-memory per request).
 *
 * Telegram guarantees single-threaded delivery per update for a bot, and we run
 * stateless PHP-FPM, so a lightweight per-process in-memory guard is enough to
 * stop duplicate callback spam within one request burst. For distributed
 * enforcement use the `rate_limit` settings and add a Redis backend.
 *
 * @package Zorvex\Infrastructure\Telegram
 */
final class WebhookRateLimiter
{
    /** @var array<int, float> Reply window end per chat id. */
    private array $windows = [];

    private readonly int $intervalSeconds;

    public function __construct(int $intervalSeconds = 2)
    {
        $this->intervalSeconds = (int) config('telegram.rate_limit', $intervalSeconds);
    }

    /**
     * Whether a new command from the given chat is allowed.
     */
    public function allow(int $chatId): bool
    {
        $now = microtime(true);

        if (isset($this->windows[$chatId]) && $now < $this->windows[$chatId]) {
            return false;
        }

        $this->windows[$chatId] = $now + $this->intervalSeconds;

        return true;
    }

    /**
     * Reset the limiter (unit tests / long-running workers).
     */
    public function reset(): void
    {
        $this->windows = [];
    }
}