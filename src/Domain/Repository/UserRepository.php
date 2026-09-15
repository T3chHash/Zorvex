<?php

declare(strict_types=1);

namespace Zorvex\Domain\Repository;

use Zorvex\Domain\Entity\User;

/**
 * User repository interface (persist / query layer abstraction).
 *
 * @package Zorvex\Domain\Repository
 */
interface UserRepository
{
    public function findById(int $id): ?User;

    public function findByTelegramId(int $telegramId): ?User;

    public function findByUsername(string $username): ?User;

    /**
     * @param list<string, mixed> $filters
     * @return list<User>
     */
    public function search(array $filters = []): array;

    /**
     * Paginated search.
     *
     * @return array{items: list<User>, total: int}
     */
    public function paginate(int $page = 1, int $limit = 20, array $filters = []): array;

    public function save(User $user): User;

    public function delete(int $id): bool;

    public function existsByTelegramId(int $telegramId): bool;

    public function countByStatus(string $status): int;

    public function count(): int;

    /**
     * Find or create a new user by their Telegram ID (upsert).
     */
    public function findOrCreateTelegramUser(int $telegramId, array $fallbackData = []): User;
}