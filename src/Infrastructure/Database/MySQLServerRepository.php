<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Database;

use Zorvex\Core\Database;
use Zorvex\Domain\Entity\Server;
use Zorvex\Domain\Repository\ServerRepository;

/**
 * MySQL-backed server (VPN panel) repository.
 *
 * @package Zorvex\Infrastructure\Database
 */
final class MySQLServerRepository implements ServerRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findById(int $id): ?Server
    {
        $row = $this->db->fetch('SELECT * FROM servers WHERE id = ? LIMIT 1', [$id]);

        return $row === null ? null : new Server($row);
    }

    public function findByType(string $type): ?Server
    {
        $row = $this->db->fetch('SELECT * FROM servers WHERE type = ? AND status = ? LIMIT 1', [$type, 'active']);

        return $row === null ? null : new Server($row);
    }

    public function all(): array
    {
        $rows = $this->db->all('SELECT * FROM servers ORDER BY created_at ASC');

        return array_map(static fn (array $row): Server => new Server($row), $rows);
    }

    public function active(): array
    {
        $rows = $this->db->all('SELECT * FROM servers WHERE status = ? ORDER BY created_at ASC', ['active']);

        return array_map(static fn (array $row): Server => new Server($row), $rows);
    }

    public function findAvailable(): array
    {
        // Servers that are active and have fewer active subscriptions than max_clients.
        $rows = $this->db->all(
            'SELECT s.* FROM servers s
             LEFT JOIN (
                 SELECT server_id, COUNT(*) AS cnt FROM subscriptions
                 WHERE status = ? GROUP BY server_id
             ) c ON c.server_id = s.id
             WHERE s.status = ? AND (s.max_clients = 0 OR c.cnt IS NULL OR c.cnt < s.max_clients)
             ORDER BY s.created_at ASC',
            ['active', 'active']
        );

        return array_map(static fn (array $row): Server => new Server($row), $rows);
    }

    public function findByName(string $name): ?Server
    {
        $row = $this->db->fetch('SELECT * FROM servers WHERE name = ? LIMIT 1', [$name]);

        return $row === null ? null : new Server($row);
    }

    public function save(Server $server): Server
    {
        $data = $server->toArray();

        if ($server->id !== null) {
            $this->db->execute(
                'UPDATE servers SET name = ?, url = ?, type = ?, api_key = ?, status = ?, subnet = ?, max_clients = ? WHERE id = ?',
                [
                    $server->name,
                    $server->url,
                    $server->type,
                    $server->apiKey,
                    $server->status,
                    $server->subnet,
                    $server->maxClients,
                    $server->id,
                ]
            );

            return new Server($data);
        }

        $this->db->execute(
            'INSERT INTO servers (name, url, type, api_key, status, subnet, max_clients, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $server->name,
                $server->url,
                $server->type,
                $server->apiKey,
                $server->status,
                $server->subnet,
                $server->maxClients,
                now(),
            ]
        );

        $data['id'] = (int) $this->db->lastInsertId();

        return new Server($data);
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM servers WHERE id = ?', [$id]);
    }

    public function count(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM servers');
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM servers WHERE status = ?', [$status]);
    }
}