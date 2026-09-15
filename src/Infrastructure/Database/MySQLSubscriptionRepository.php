<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Database;

use Zorvex\Core\Database;
use Zorvex\Domain\Entity\Product;
use Zorvex\Domain\Entity\Subscription;
use Zorvex\Domain\Repository\SubscriptionRepository;

/**
 * MySQL-backed subscription and product repository.
 *
 * @package Zorvex\Infrastructure\Database
 */
final class MySQLSubscriptionRepository implements SubscriptionRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findById(int $id): ?Subscription
    {
        $row = $this->db->fetch('SELECT * FROM subscriptions WHERE id = ? LIMIT 1', [$id]);

        return $row === null ? null : new Subscription($row);
    }

    public function findByUser(int $userId): array
    {
        $rows = $this->db->all(
            'SELECT * FROM subscriptions WHERE user_id = ? ORDER BY created_at DESC',
            [$userId]
        );

        return array_map(static fn (array $row): Subscription => new Subscription($row), $rows);
    }

    public function findActiveByUserAndServer(int $userId, int $serverId): ?Subscription
    {
        $row = $this->db->fetch(
            'SELECT * FROM subscriptions WHERE user_id = ? AND server_id = ? AND status = ? LIMIT 1',
            [$userId, $serverId, 'active']
        );

        return $row === null ? null : new Subscription($row);
    }

    public function findActiveByUser(int $userId): ?Subscription
    {
        $row = $this->db->fetch(
            'SELECT * FROM subscriptions WHERE user_id = ? AND status = ? AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP()) LIMIT 1',
            [$userId, 'active']
        );

        return $row === null ? null : new Subscription($row);
    }

    public function findByUsername(string $username): ?Subscription
    {
        $row = $this->db->fetch('SELECT * FROM subscriptions WHERE username = ? LIMIT 1', [$username]);

        return $row === null ? null : new Subscription($row);
    }

    public function all(): array
    {
        $rows = $this->db->all('SELECT * FROM subscriptions ORDER BY created_at DESC');

        return array_map(static fn (array $row): Subscription => new Subscription($row), $rows);
    }

    public function save(Subscription $subscription): Subscription
    {
        $data = $subscription->toArray();

        if ($subscription->id !== null) {
            $this->db->execute(
                'UPDATE subscriptions SET user_id = ?, server_id = ?, product_id = ?, username = ?, status = ?, ' .
                'traffic_used = ?, traffic_limit = ?, expires_at = ?, activated_at = ?, config_link = ?, panel_payload = ? WHERE id = ?',
                [
                    $subscription->userId,
                    $subscription->serverId,
                    $subscription->productId,
                    $subscription->username,
                    $subscription->status,
                    $subscription->trafficUsed,
                    $subscription->trafficLimit,
                    $subscription->expiresAt,
                    $subscription->activatedAt,
                    $subscription->configLink,
                    $subscription->panelPayload,
                    $subscription->id,
                ]
            );

            return new Subscription($data);
        }

        $this->db->execute(
            'INSERT INTO subscriptions (user_id, server_id, product_id, username, status, traffic_used, traffic_limit, ' .
            'expires_at, activated_at, config_link, panel_payload, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $subscription->userId,
                $subscription->serverId,
                $subscription->productId,
                $subscription->username,
                $subscription->status,
                $subscription->trafficUsed,
                $subscription->trafficLimit,
                $subscription->expiresAt,
                $subscription->activatedAt,
                $subscription->configLink,
                $subscription->panelPayload,
                now(),
            ]
        );

        $data['id'] = (int) $this->db->lastInsertId();

        return new Subscription($data);
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM subscriptions WHERE id = ?', [$id]);
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM subscriptions');
    }

    public function countActive(): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM subscriptions WHERE status = ? AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())',
            ['active']
        );
    }

    public function revokeByServer(int $serverId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE subscriptions SET status = ? WHERE server_id = ? AND status <> ?'
        );
        $stmt->execute(['suspended', $serverId, 'suspended']);

        return $stmt->rowCount();
    }

    public function expireOverdue(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE subscriptions SET status = ? WHERE status = ? AND expires_at IS NOT NULL AND expires_at <= UTC_TIMESTAMP()'
        );
        $stmt->execute(['expired', 'active']);

        return $stmt->rowCount();
    }

    // ---- Products ----

    public function findProductById(int $id): ?Product
    {
        $row = $this->db->fetch('SELECT * FROM products WHERE id = ? LIMIT 1', [$id]);

        return $row === null ? null : new Product($row);
    }

    public function products(): array
    {
        $rows = $this->db->all('SELECT * FROM products ORDER BY price ASC');

        return array_map(static fn (array $row): Product => new Product($row), $rows);
    }

    public function availableProducts(): array
    {
        $rows = $this->db->all(
            'SELECT p.* FROM products p
             INNER JOIN servers s ON s.id = p.server_id AND s.status = ?
             WHERE p.status = ?
             ORDER BY p.price ASC',
            ['active', 'active']
        );

        return array_map(static fn (array $row): Product => new Product($row), $rows);
    }

    public function productsForServer(int $serverId): array
    {
        $rows = $this->db->all(
            'SELECT * FROM products WHERE server_id = ? AND status = ? ORDER BY price ASC',
            [$serverId, 'active']
        );

        return array_map(static fn (array $row): Product => new Product($row), $rows);
    }

    public function saveProduct(Product $product): Product
    {
        $data = $product->toArray();

        if ($product->id !== null) {
            $this->db->execute(
                'UPDATE products SET server_id = ?, name = ?, description = ?, price = ?, duration_days = ?, `traffic` = ?, status = ? WHERE id = ?',
                [
                    $product->serverId,
                    $product->name,
                    $product->description,
                    $product->price,
                    $product->durationDays,
                    $product->trafficBytes,
                    $product->status,
                    $product->id,
                ]
            );

            return new Product($data);
        }

        $this->db->execute(
            'INSERT INTO products (server_id, name, description, price, duration_days, `traffic`, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $product->serverId,
                $product->name,
                $product->description,
                $product->price,
                $product->durationDays,
                $product->trafficBytes,
                $product->status,
                now(),
            ]
        );

        $data['id'] = (int) $this->db->lastInsertId();

        return new Product($data);
    }

    public function deleteProduct(int $id): bool
    {
        return $this->db->execute('DELETE FROM products WHERE id = ?', [$id]);
    }
}