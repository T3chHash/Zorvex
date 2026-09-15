<?php

declare(strict_types=1);

namespace Zorvex\Domain\Repository;

use Zorvex\Domain\Entity\Product;
use Zorvex\Domain\Entity\Subscription;

/**
 * Subscription and product repository interface.
 *
 * @package Zorvex\Domain\Repository
 */
interface SubscriptionRepository
{
    public function findById(int $id): ?Subscription;

    /**
     * @return list<Subscription>
     */
    public function findByUser(int $userId): array;

    public function findActiveByUserAndServer(int $userId, int $serverId): ?Subscription;

    public function findActiveByUser(int $userId): ?Subscription;

    public function findByUsername(string $username): ?Subscription;

    /**
     * @return list<Subscription>
     */
    public function all(): array;

    public function save(Subscription $subscription): Subscription;

    public function delete(int $id): bool;

    public function count(): int;

    public function countActive(): int;

    public function revokeByServer(int $serverId): int;

    /**
     * Mark subscriptions expired when past their expiry date (cron).
     */
    public function expireOverdue(): int;

    // ---- Products ----

    public function findProductById(int $id): ?Product;

    /**
     * @return list<Product>
     */
    public function products(): array;

    /**
     * Active products available for sale.
     *
     * @return list<Product>
     */
    public function availableProducts(): array;

    /**
     * @return list<Product>
     */
    public function productsForServer(int $serverId): array;

    public function saveProduct(Product $product): Product;

    public function deleteProduct(int $id): bool;
}