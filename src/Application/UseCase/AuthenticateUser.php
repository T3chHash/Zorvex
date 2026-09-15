<?php

declare(strict_types=1);

namespace Zorvex\Application\UseCase;

use Zorvex\Domain\Entity\User;
use Zorvex\Domain\Repository\UserRepository;

/**
 * AuthenticateUser — load or create a user from a Telegram identity, and
 * promote them to admin when their Telegram ID is in the admin allow list.
 *
 * @package Zorvex\Application\UseCase
 */
final class AuthenticateUser
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * Load the user with the given Telegram ID or create a new record.
     *
     * @param array<string, mixed> $fallbackData
     */
    public function fromTelegram(int $telegramId, array $fallbackData = []): User
    {
        $user = $this->users->findOrCreateTelegramUser($telegramId, $fallbackData);

        return $user;
    }

    /**
     * Whether the Telegram ID belongs to a configured admin.
     */
    public function isAdmin(int $telegramId): bool
    {
        return in_array($telegramId, array_map('intval', (array) config('telegram.admin_ids', [])), true);
    }

    /**
     * Ban a user by changing their status.
     */
    public function ban(int $userId): ?User
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return null;
        }

        $updated = $this->users->save(new User($user->toArray() + ['status' => 'banned']));

        return $updated;
    }

    /**
     * Unban a user, restoring active status.
     */
    public function unban(int $userId): ?User
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return null;
        }

        return $this->users->save(new User($user->toArray() + ['status' => 'active']));
    }
}