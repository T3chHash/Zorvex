<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram;

use Zorvex\Application\UseCase\AuthenticateUser;
use Zorvex\Domain\Entity\User;

/**
 * Authenticates a Telegram actor for the current request.
 *
 * Resolves a User entity from the update's `from` block and injects it into
 * the container so downstream services can fetch `User` directly.
 *
 * @package Zorvex\Infrastructure\Telegram
 */
final class Authenticator
{
    public function __construct(private readonly AuthenticateUser $authenticate)
    {
    }

    /**
     * Load-or-create a user by Telegram ID and expose it via the container.
     *
     * @param array<string, mixed> $from
     */
    public function identify(int $telegramId, array $from = []): User
    {
        $user = $this->authenticate->fromTelegram($telegramId, [
            'username' => (string) ($from['username'] ?? ''),
            'first_name' => (string) ($from['first_name'] ?? ''),
            'last_name' => (string) ($from['last_name'] ?? ''),
        ]);

        // Bind so `app(User::class)` returns the current actor for this update.
        app()->instance(User::class, $user);

        return $user;
    }
}