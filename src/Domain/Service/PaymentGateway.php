<?php

declare(strict_types=1);

namespace Zorvex\Domain\Service;

use Zorvex\Domain\Entity\Invoice;

/**
 * Payment gateway interface.
 *
 * Every supported payment method (crypto, card-to-card, online gateway)
 * implements this contract so the application layer can settle invoices
 * uniformly.
 *
 * @package Zorvex\Domain\Service
 */
interface PaymentGateway
{
    /**
     * The unique gateway/method identifier (e.g. 'crypto', 'zarinpal').
     */
    public function id(): string;

    /**
     * Whether the gateway supports ATS (amount-to-settle) for this invoice.
     */
    public function supports(Invoice $invoice): bool;

    /**
     * Start a payment for the given invoice.
     *
     * @return array{pay_url: string, lookup: string, extra?: array<string, mixed>}
     *         - pay_url: where the user completes the payment.
     *         - lookup:  token that the callback uses to reference this session.
     * @throws \RuntimeException when initiation fails.
     */
    public function createPayment(Invoice $invoice): array;

    /**
     * Verify a payment after the user returns from the gateway.
     *
     * @param array<string, mixed> $params Callback query params supplied by the gateway.
     * @return array{status: string, reference: string, message?: string}
     */
    public function verifyPayment(string $lookup, array $params = []): array;

    /**
     * Resolve a human readable label for the gateway in the UI.
     */
    public function label(): string;

    /**
     * Return a small badge / accent color used by the mini-app.
     */
    public function color(): string;
}