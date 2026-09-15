<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Payment;

use Zorvex\Domain\Entity\Invoice;
use Zorvex\Domain\Service\PaymentGateway;

/**
 * Cryptocurrency (USDT/TRX/TON on-chain) payment gateway.
 *
 * The bot exposes a static wallet address. The user sends funds to that address
 * and submits a transaction hash as proof, which an operator verifies before
 * marking the payment completed.
 *
 * @package Zorvex\Infrastructure\Payment
 */
final class CryptoGateway implements PaymentGateway
{
    public function id(): string
    {
        return 'crypto';
    }

    public function supports(Invoice $invoice): bool
    {
        return config('payment.crypto.wallet') !== '';
    }

    public function createPayment(Invoice $invoice): array
    {
        $wallet = (string) (config('payment.crypto.wallet') ?? '');
        $network = (string) (config('payment.crypto.network') ?? 'TRC20');
        $rate = (float) (config('payment.crypto.usdt_rate') ?? 1.0);
        $amountUsdt = $rate > 0 ? round($invoice->amount / $rate, 2) : 0.0;

        return [
            'pay_url' => '',
            'lookup' => $invoice->uuid,
            'extra' => [
                'wallet' => $wallet,
                'network' => $network,
                'amount_usdt' => $amountUsdt,
                'amount_toman' => $invoice->amount,
            ],
        ];
    }

    public function verifyPayment(string $lookup, array $params = []): array
    {
        $invoice = $this->invoiceFor($lookup);
        if ($invoice === null) {
            return ['status' => 'failed', 'reference' => '', 'message' => 'Invoice not found.'];
        }

        $txnHash = (string) ($params['hash'] ?? $params['txid'] ?? $params['reference'] ?? '');

        if ($txnHash === '' || !preg_match('/^[a-fA-F0-9]{32,128}$/', $txnHash)) {
            return [
                'status' => 'pending',
                'reference' => '',
                'message' => 'A valid transaction hash is required for verification.',
            ];
        }

        // NOTE: real deployments should validate on-chain confirmations here
        // using a provider API (TRONGrid, Etherscan, TON Center) — the proof is
        // stored and the operator confirms.
        return ['status' => 'pending', 'reference' => $txnHash, 'message' => 'Awaiting on-chain confirmation.'];
    }

    public function label(): string
    {
        return 'کریپتو / ارز دیجیتال';
    }

    public function color(): string
    {
        return '#f7931a';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function invoiceFor(string $uuid): ?array
    {
        $db = app(\Zorvex\Core\Database::class);
        $row = $db->fetch('SELECT * FROM invoices WHERE uuid = ? LIMIT 1', [$uuid]);

        return $row;
    }
}