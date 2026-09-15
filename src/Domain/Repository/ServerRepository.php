<?php

declare(strict_types=1);

namespace Zorvex\Domain\Repository;

use Zorvex\Domain\Entity\Server;

/**
 * Server repository interface.
 *
 * @package Zorvex\Domain\Repository
 */
interface ServerRepository
{
    public function findById(int $id): ?Server;

    public function findByType(string $type): ?Server;

    public function all(): array;

    public function active(): array;

    public function findAvailable(): array;

    public function findByName(string $name): ?Server;

    public function save(Server $server): Server;

    public function delete(int $id): bool;

    public function count(): int;

    public function countByStatus(string $status): int;
}