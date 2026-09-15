<?php

declare(strict_types=1);

namespace Zorvex\Domain\Entity;

/**
 * Invoice entity. Documents a purchase intent and its settlement state.
 *
 * @package Zorvex\Domain\Entity
 */
final class Invoice
{
    public readonly ?int $id;
    public readonly string $uuid;
    public readonly int $userId;
    public readonly ?int $subscriptionId;
    public readonly ?int $productId;
    public readonly string $status;
    public readonly float $amount;
    public readonly ?string $description;

    public function __construct(array $data)
    {
        $this->id = isset($data['id']) && $data['id'] !== null ? (int) $data['id'] : null;
        $this->uuid = (string) ($data['uuid'] ?? '');
        $this->userId = (int) ($data['user_id'] ?? 0);
        $this->subscriptionId = isset($data['subscription_id']) && $data['subscription_id'] !== null
            ? (int) $data['subscription_id']
            : null;
        $this->productId = isset($data['product_id']) && $data['product_id'] !== null
            ? (int) $data['product_id']
            : null;
        $this->status = (string) ($data['status'] ?? Status::Pending->value);
        $this->amount = round((float) ($data['amount'] ?? 0.0), 2);
        $this->description = isset($data['description']) && $data['description'] !== ''
            ? (string) $data['description']
            : null;
    }

    public function isPending(): bool
    {
        return $this->status === Status::Pending->value;
    }

    public function isPaid(): bool
    {
        return in_array($this->status, [
            Status::Completed->value,
            Status::Processing->value,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'user_id' => $this->userId,
            'subscription_id' => $this->subscriptionId,
            'product_id' => $this->productId,
            'status' => $this->status,
            'amount' => $this->amount,
            'description' => $this->description,
        ];
    }
}