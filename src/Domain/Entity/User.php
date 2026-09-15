<?php

declare(strict_types=1);

namespace Zorvex\Domain\Entity;

/**
 * User aggregate root.
 *
 * @package Zorvex\Domain\Entity
 */
final class User
{
    public const ROLE_USER = 'user';
    public const ROLE_ADMIN = 'admin';

    public readonly ?int $id;
    public readonly int $telegramId;
    public readonly string $username;
    public readonly string $firstName;
    public readonly string $lastName;
    public readonly string $role;
    public readonly string $status;
    public readonly string $phone;
    public readonly float $balance;
    public readonly ?int $vpnServerId;
    public readonly ?string $vpnUsername;
    public readonly string $createdAt;
    public readonly string $updatedAt;

    public function __construct(array $data)
    {
        $this->id = isset($data['id']) && $data['id'] !== null ? (int) $data['id'] : null;
        $this->telegramId = (int) ($data['telegram_id'] ?? 0);
        $this->username = (string) ($data['username'] ?? '');
        $this->firstName = (string) ($data['first_name'] ?? '');
        $this->lastName = (string) ($data['last_name'] ?? '');
        $this->role = self::ROLE_USER;
        $this->status = (string) ($data['status'] ?? 'active');
        $this->phone = (string) ($data['phone'] ?? '');
        $this->balance = round((float) ($data['balance'] ?? 0.0), 2);
        $this->vpnServerId = isset($data['vpn_server_id']) && $data['vpn_server_id'] !== null
            ? (int) $data['vpn_server_id']
            : null;
        $this->vpnUsername = isset($data['vpn_username']) && $data['vpn_username'] !== ''
            ? (string) $data['vpn_username']
            : null;
        $this->createdAt = (string) ($data['created_at'] ?? '');
        $this->updatedAt = (string) ($data['updated_at'] ?? '');
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isBanned(): bool
    {
        return $this->status === 'banned';
    }

    public function hasBalance(float $amount): bool
    {
        return $this->balance >= $amount;
    }

    public function displayName(): string
    {
        $name = trim($this->firstName . ' ' . $this->lastName);

        return $name !== '' ? $name : '@' . $this->username;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'telegram_id' => $this->telegramId,
            'username' => $this->username,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'role' => $this->role,
            'status' => $this->status,
            'phone' => $this->phone,
            'balance' => $this->balance,
            'vpn_server_id' => $this->vpnServerId,
            'vpn_username' => $this->vpnUsername,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}