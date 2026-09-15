<?php

declare(strict_types=1);

namespace Zorvex\Domain\Entity;

/**
 * VPN server (panel) entity. Supports Marzban and 3x-ui panel types.
 *
 * @package Zorvex\Domain\Entity
 */
final class Server
{
    public readonly ?int $id;
    public readonly string $name;
    public readonly string $url;
    public readonly string $type;
    public readonly string $apiKey;
    public readonly string $status;
    public readonly ?string $subnet;
    public readonly int $maxClients;
    public readonly string $createdAt;

    public function __construct(array $data)
    {
        $this->id = isset($data['id']) && $data['id'] !== null ? (int) $data['id'] : null;
        $this->name = (string) ($data['name'] ?? '');
        $this->url = rtrim((string) ($data['url'] ?? ''), '/');
        $this->type = (string) ($data['type'] ?? 'marzban');
        $this->apiKey = (string) ($data['api_key'] ?? '');
        $this->status = (string) ($data['status'] ?? 'active');
        $this->subnet = isset($data['subnet']) && $data['subnet'] !== '' ? (string) $data['subnet'] : null;
        $this->maxClients = (int) ($data['max_clients'] ?? 0);
        $this->createdAt = (string) ($data['created_at'] ?? '');
    }

    public function isMarzban(): bool
    {
        return strtolower($this->type) === 'marzban';
    }

    public function isXui(): bool
    {
        return in_array(strtolower($this->type), ['xui', '3x-ui', '3-ui'], true);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Build a standard HTTP Authorization header for this panel.
     */
    public function authHeader(): string
    {
        return 'Authorization: Bearer ' . $this->apiKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'type' => $this->type,
            'api_key' => $this->apiKey,
            'status' => $this->status,
            'subnet' => $this->subnet,
            'max_clients' => $this->maxClients,
            'created_at' => $this->createdAt,
        ];
    }
}