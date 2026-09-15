<?php

declare(strict_types=1);

namespace Zorvex\Domain\Entity;

/**
 * Payment entity. A monetary transfer tied to an invoice.
 *
 * @package Zorvex\Domain\Entity
 */
final class Payment
{
    public const METHOD_CRYPTO = 'crypto';
    public const METHOD_CARD = 'card_to_card';
    public const METHOD_GATEWAY = 'gateway';

    public readonly ?int $id;
    public readonly int $userId;
    public readonly ?int $invoiceId;
    public readonly string $method;
    public readonly string $status;
    public readonly float $amount;
    public readonly ?string $currency;
    public readonly string $orderId;
    public readonly ?string $externalRef;
    public readonly ?string $proofFile;
    public readonly ?string $meta;
    public readonly string $createdAt;
    public readonly ?string $paidAt;

    public function __construct(array $data)
    {
        $this->id = isset($data['id']) && $data['id'] !== null ? (int) $data['id'] : null;
        $this->userId = (int) ($data['user_id'] ?? 0);
        $this->invoiceId = isset($data['invoice_id']) && $data['invoice_id'] !== null
            ? (int) $data['invoice_id']
            : null;
        $this->method = (string) ($data['method'] ?? self::METHOD_GATEWAY);
        $this->status = (string) ($data['status'] ?? Status::Pending->value);
        $this->amount = round((float) ($data['amount'] ?? 0.0), 2);
        $this->currency = isset($data['currency']) && $data['currency'] !== '' ? (string) $data['currency'] : null;
        $this->orderId = (string) ($data['order_id'] ?? '');
        $this->externalRef = isset($data['external_ref']) && $data['external_ref'] !== ''
            ? (string) $data['external_ref']
            : null;
        $this->proofFile = isset($data['proof_file']) && $data['proof_file'] !== ''
            ? (string) $data['proof_file']
            : null;
        $this->meta = isset($data['meta']) && $data['meta'] !== '' ? (string) $data['meta'] : null;
        $this->createdAt = (string) ($data['created_at'] ?? '');
        $this->paidAt = isset($data['paid_at']) && $data['paid_at'] !== '' ? (string) $data['paid_at'] : null;
    }

    public function isPending(): bool
    {
        return $this->status === Status::Pending->value;
    }

    public function isCompleted(): bool
    {
        return $this->status === Status::Completed->value;
    }

    /**
     * Decode the JSON metadata bag.
     *
     * @return array<string, mixed>
     */
    public function metaBag(): array
    {
        if ($this->meta === null) {
            return [];
        }

        $decoded = json_decode($this->meta, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'invoice_id' => $this->invoiceId,
            'method' => $this->method,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'order_id' => $this->orderId,
            'external_ref' => $this->externalRef,
            'proof_file' => $this->proofFile,
            'meta' => $this->meta,
            'created_at' => $this->createdAt,
            'paid_at' => $this->paidAt,
        ];
    }
}