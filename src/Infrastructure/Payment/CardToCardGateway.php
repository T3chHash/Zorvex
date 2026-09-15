<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Payment;

use Zorvex\Core\Database;
use Zorvex\Domain\Entity\Invoice;
use Zorvex\Domain\Service\PaymentGateway;

/**
 * Card-to-card payment gateway.
 *
 * Payment instructions are returned (a card number + amount). The user sends a
 * receipt (photo + transaction ID) which an operator manually verifies through
 * the admin flow. This gateway is "offline" — the lookup token maps to the
 * invoice UUID and verification happens by an admin action.
 *
 * @package Zorvex\Infrastructure\Payment
 */
final class CardToCardGateway implements PaymentGateway
{
    public function __construct(private readonly Database $db)
    {
    }

    public function id(): string
    {
        return 'card_to_card';
    }

    public function supports(Invoice $invoice): bool
    {
        return (bool) (config('payment.card_to_card.enabled') ?? true);
    }

    public function createPayment(Invoice $invoice): array
    {
        $card = (string) (config('payment.card_to_card.card_number') ?? '');
        $holder = (string) (config('payment.card_to_card.card_holder') ?? '');

        // The user must pay the exact invoice amount in Toman.
        return [
            'pay_url' => '',
            'lookup' => $invoice->uuid,
            'extra' => [
                'card' => $card,
                'card_holder' => $holder,
                'amount' => $invoice->amount,
            ],
        ];
    }

    public function verifyPayment(string $lookup, array $params = []): array
    {
        $invoice = $this->db->fetch('SELECT * FROM invoices WHERE uuid = ? LIMIT 1', [$lookup]);

        if ($invoice === null) {
            return ['status' => 'failed', 'reference' => '', 'message' => 'Invoice not found.'];
        }

        // Manual gateways always require an operator confirm; the stored
        // reference (photo proof) is attached by the caller beforehand.
        $reference = (string) ($params['reference'] ?? '');

        if ($reference === '' && (bool) config('payment.card_to_card.auto_verify') === false) {
            return [
                'status' => 'pending',
                'reference' => '',
                'message' => 'Card-to-card payments require manual operator confirmation.',
            ];
        }

        return ['status' => 'completed', 'reference' => $reference];
    }

    public function label(): string
    {
        return 'کارت به کارت';
    }

    public function color(): string
    {
        return '#22c55e';
    }
}