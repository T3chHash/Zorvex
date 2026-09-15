<?php

declare(strict_types=1);

namespace Zorvex\Domain\Entity;

/**
 * Product entity. A purchasable subscription package on a server.
 *
 * @package Zorvex\Domain\Entity
 */
final class Product
{
    public readonly ?int $id;
    public readonly int $serverId;
    public readonly string $name;
    public readonly string $description;
    public readonly float $price;
    public readonly int $durationDays;
    public readonly int $trafficBytes;
    public readonly string $status;
    public readonly string $createdAt;

    public function __construct(array $data)
    {
        $this->id = isset($data['id']) && $data['id'] !== null ? (int) $data['id'] : null;
        $this->serverId = (int) ($data['server_id'] ?? 0);
        $this->name = (string) ($data['name'] ?? '');
        $this->description = (string) ($data['description'] ?? '');
        $this->price = round((float) ($data['price'] ?? 0.0), 2);
        $this->durationDays = (int) ($data['duration_days'] ?? 0);
        $this->trafficBytes = (int) ($data['traffic'] ?? $data['traffic_bytes'] ?? 0);
        $this->status = (string) ($data['status'] ?? 'active');
        $this->createdAt = (string) ($data['created_at'] ?? '');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function trafficLabel(): string
    {
        return format_bytes($this->trafficBytes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'server_id' => $this->serverId,
            'name' => $this->name,
            'description' => $this->description,
            'price' => $this->price,
            'duration_days' => $this->durationDays,
            'traffic' => $this->trafficBytes,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'traffic_label' => $this->trafficLabel(),
        ];
    }
}