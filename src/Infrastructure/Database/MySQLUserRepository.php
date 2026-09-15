<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Database;

use PDO;
use RuntimeException;
use Zorvex\Core\Database;
use Zorvex\Domain\Entity\User;
use Zorvex\Domain\Repository\UserRepository;

/**
 * MySQL-backed user repository (PDO prepared statements only).
 *
 * @package Zorvex\Infrastructure\Database
 */
final class MySQLUserRepository implements UserRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findById(int $id): ?User
    {
        $row = $this->db->fetch('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);

        return $row === null ? null : new User($row);
    }

    public function findByTelegramId(int $telegramId): ?User
    {
        $row = $this->db->fetch('SELECT * FROM users WHERE telegram_id = ? LIMIT 1', [$telegramId]);

        return $row === null ? null : new User($row);
    }

    public function findByUsername(string $username): ?User
    {
        $row = $this->db->fetch('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);

        return $row === null ? null : new User($row);
    }

    public function search(array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);

        $rows = $this->db->all(
            'SELECT * FROM users ' . $where . ' ORDER BY created_at DESC',
            $params
        );

        return array_map(static fn (array $row): User => new User($row), $rows);
    }

    public function paginate(int $page = 1, int $limit = 20, array $filters = []): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $offset = max(0, ($page - 1) * $limit);

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM users ' . $where,
            $params
        );

        $rows = $this->db->all(
            'SELECT * FROM users ' . $where . ' ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [...$params, $limit, $offset]
        );

        return [
            'items' => array_map(static fn (array $row): User => new User($row), $rows),
            'total' => $total,
        ];
    }

    public function save(User $user): User
    {
        $data = $user->toArray();

        if ($user->id !== null) {
            $this->db->execute(
                'UPDATE users SET telegram_id = ?, username = ?, first_name = ?, last_name = ?, ' .
                'status = ?, phone = ?, balance = ?, vpn_server_id = ?, vpn_username = ?, updated_at = ? WHERE id = ?',
                [
                    $user->telegramId,
                    $user->username,
                    $user->firstName,
                    $user->lastName,
                    $user->status,
                    $user->phone,
                    $user->balance,
                    $user->vpnServerId,
                    $user->vpnUsername,
                    now(),
                    $user->id,
                ]
            );

            return new User($data);
        }

        $this->db->execute(
            'INSERT INTO users (telegram_id, username, first_name, last_name, status, phone, balance, vpn_server_id, vpn_username, created_at, updated_at) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $user->telegramId,
                $user->username,
                $user->firstName,
                $user->lastName,
                $user->status,
                $user->phone,
                $user->balance,
                $user->vpnServerId,
                $user->vpnUsername,
                now(),
                now(),
            ]
        );

        $data['id'] = (int) $this->db->lastInsertId();

        return new User($data);
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM users WHERE id = ?', [$id]);
    }

    public function existsByTelegramId(int $telegramId): bool
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE telegram_id = ?', [$telegramId]) > 0;
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM users WHERE status = ?', [$status]);
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM users');
    }

    public function findOrCreateTelegramUser(int $telegramId, array $fallbackData = []): User
    {
        $existing = $this->findByTelegramId($telegramId);

        if ($existing !== null) {
            return $existing;
        }

        $user = new User([
            'telegram_id' => $telegramId,
            'username' => (string) ($fallbackData['username'] ?? ''),
            'first_name' => (string) ($fallbackData['first_name'] ?? ''),
            'last_name' => (string) ($fallbackData['last_name'] ?? ''),
            'status' => 'active',
            'phone' => '',
            'balance' => 0,
        ]);

        return $this->save($user);
    }

    /**
     * Build a safe WHERE clause + bound params from whitelisted filter keys.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (isset($filters['status']) && is_string($filters['status']) && $filters['status'] !== '') {
            $clauses[] = 'status = ?';
            $params[] = $filters['status'];
        }

        if (isset($filters['role']) && is_string($filters['role']) && $filters['role'] !== '') {
            $clauses[] = 'role = ?';
            $params[] = $filters['role'];
        }

        if (isset($filters['q']) && is_string($filters['q']) && trim($filters['q']) !== '') {
            $clauses[] = '(username LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR telegram_id LIKE ?)';
            $like = '%' . trim($filters['q']) . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);

        return [$where, $params];
    }
}