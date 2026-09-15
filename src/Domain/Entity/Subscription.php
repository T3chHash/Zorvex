<?php

declare(strict_types=1);

namespace Zorvex\Domain\Entity;

/**
 * Subscription entity. A user's VPN access on a specific server.
 *
 * @package Zorvex\Domain\Entity
 */
final class Subscription
{
    public readonly ?int $id;
    public readonly int $userId;
    public readonly int $serverId;
    public readonly ?int $productId;
    public readonly string $username;
    public readonly string $status;
    public readonly int $trafficUsed;
    public readonly int $trafficLimit;
    public readonly ?string $expiresAt;
    public readonly ?string $activatedAt;
    public readonly ?string $configLink;
    public readonly ?string $panelPayload;
    public readonly string $createdAt;

    public function __construct(array $data)
    {
        $this->id = isset($data['id']) && $data['id'] !== null ? (int) $data['id'] : null;
        $this->userId = (int) ($data['user_id'] ?? 0);
        $this->serverId = (int) ($data['server_id'] ?? 0);
        $this->productId = isset($data['product_id']) && $data['product_id'] !== null
            ? (int) $data['product_id']
            : null;
        $this->username = (string) ($data['username'] ?? '');
        $this->status = (string) ($data['status'] ?? Status::Pending->value);
        $this->trafficUsed = (int) ($data['traffic_used'] ?? 0);
        $this->trafficLimit = (int) ($data['traffic_limit'] ?? 0);
        $this->expiresAt = isset($data['expires_at']) && $data['expires_at'] !== null && $data['expires_at'] !== ''
            ? (string) $data['expires_at']
            : null;
        $this->activatedAt = isset($data['activated_at']) && $data['activated_at'] !== null && $data['activated_at'] !== ''
            ? (string) $data['activated_at']
            : null;
        $this->configLink = isset($data['config_link']) && $data['config_link'] !== ''
            ? (string) $data['config_link']
            : null;
        $this->panelPayload = isset($data['panel_payload']) && $data['panel_payload'] !== ''
            ? (string) $data['panel_payload']
            : null;
        $this->createdAt = (string) ($data['created_at'] ?? '');
    }

    public function isActive(): bool
    {
        return $this->status === Status::Active->value && !$this->hasExpired();
    }

    public function hasExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return strtotime($this->expiresAt) < time();
    }

    public function remainingDays(): int
    {
        if ($this->expiresAt === null) {
            return -1;
        }

        return (int) ceil((strtotime($this->expiresAt) - time()) / 86400);
    }

    public function remainingTraffic(): int
    {
        return max(0, $this->trafficLimit - $this->trafficUsed);
    }

    public function trafficUsedPercent(): int
    {
        if ($this->trafficLimit <= 0) {
            return 0;
        }

        return (int) min(100, round($this->trafficUsed / $this->trafficLimit * 100));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'server_id' => $this->serverId,
            'product_id' => $this->productId,
            'username' => $this->username,
            'status' => $this->status,
            'traffic_used' => $this->trafficUsed,
            'traffic_limit' => $this->trafficLimit,
            'expires_at' => $this->expiresAt,
            'activated_at' => $this->activatedAt,
            'config_link' => $this->configLink,
            'panel_payload' => $this->panelPayload,
            'created_at' => $this->createdAt,
            'is_active' => $this->isActive(),
            'remaining_days' => $this->remainingDays(),
            'traffic_used_percent' => $this->trafficUsedPercent(),
        ];
    }
}