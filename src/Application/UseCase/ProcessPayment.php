<?php

declare(strict_types=1);

namespace Zorvex\Application\UseCase;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Zorvex\Core\Database;
use Zorvex\Domain\Entity\Invoice;
use Zorvex\Domain\Entity\Payment;
use Zorvex\Domain\Entity\Product;
use Zorvex\Domain\Entity\User;
use Zorvex\Domain\Repository\PaymentRepository;
use Zorvex\Domain\Repository\SubscriptionRepository;
use Zorvex\Domain\Repository\UserRepository;
use Zorvex\Domain\Service\PaymentGateway;

/**
 * ProcessPayment — create invoices, route payments to gateways, verify
 * callbacks and settle invoices by activating the subscription.
 *
 * @package Zorvex\Application\UseCase
 */
final class ProcessPayment
{
    /** @var array<string, PaymentGateway> */
    private array $gateways = [];

    /**
     * @param iterable<PaymentGateway> $gateways
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly PaymentRepository $payments,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PurchaseSubscription $purchase,
        private readonly RenewSubscription $renewal,
        private readonly Database $db,
        iterable $gateways,
        private readonly LoggerInterface $logger,
    ) {
        foreach ($gateways as $gateway) {
            $this->gateways[$gateway->id()] = $gateway;
        }
    }

    /**
     * Create an invoice (+ payment intent) for a product purchase.
     *
     * @throws RuntimeException
     */
    public function prepare(int $telegramId, int $productId, string $method): Invoice
    {
        $user = $this->users->findByTelegramId($telegramId);
        if ($user === null) {
            throw new RuntimeException('ابتدا /start را اجرا کنید.');
        }

        if ($user->isBanned()) {
            throw new RuntimeException('حساب شما مسدود شده است.');
        }

        $product = $this->subscriptions->findProductById($productId);
        if ($product === null || !$product->isActive()) {
            throw new RuntimeException('محصول یافت نشد یا در دسترس نیست.');
        }

        $gateway = $this->gateways[$method] ?? null;
        if ($gateway === null) {
            throw new RuntimeException('روش پرداختی نامعتبر است.');
        }

        // Reuse an existing pending invoice for the same product to avoid
        // duplicate bills when users hammer the button.
        $existing = $this->payments->findPendingInvoiceByUserAndProduct((int) $user->id, $productId);
        if ($existing !== null) {
            return $existing;
        }

        // Build invoice.
        $invoice = $this->payments->saveInvoice(new Invoice([
            'uuid' => uid('inv'),
            'user_id' => $user->id,
            'subscription_id' => null,
            'product_id' => $productId,
            'status' => 'pending',
            'amount' => $product->price,
            'description' => 'خرید ' . $product->name,
        ]));

        // Init the payment intent with the gateway.
        $intent = $gateway->createPayment($invoice);

        // Persist the payment order.
        $this->payments->savePayment(new Payment([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'method' => $method,
            'status' => 'pending',
            'amount' => $product->price,
            'currency' => $method === 'crypto' ? 'USDT' : null,
            'order_id' => $invoice->uuid,
            'external_ref' => $intent['lookup'] ?? null,
            'meta' => json_encode(['gateway' => $method, 'extra' => $intent['extra'] ?? []]),
        ]));

        $this->logger->info('Invoice created', [
            'invoice' => $invoice->uuid,
            'user' => $user->id,
            'product' => $productId,
            'method' => $method,
        ]);

        return $invoice;
    }

    /**
     * Attempt to pay an invoice from the user's internal wallet balance.
     *
     * Debits `balance` and activates the subscription in one transaction when
     * the wallet contains enough funds.
     *
     * @throws RuntimeException
     */
    public function payFromWallet(int $telegramId, int $productId): Invoice
    {
        return $this->db->transaction(function () use ($telegramId, $productId): Invoice {
            $user = $this->users->findByTelegramId($telegramId);
            if ($user === null) {
                throw new RuntimeException('ابتدا /start را اجرا کنید.');
            }

            $product = $this->subscriptions->findProductById($productId);
            if ($product === null) {
                throw new RuntimeException('محصول نامعتبر.');
            }

            if (!$user->hasBalance($product->price)) {
                throw new RuntimeException('موجودی کیف پول کافی نیست.');
            }

            $invoice = $this->payments->saveInvoice(new Invoice([
                'uuid' => uid('inv'),
                'user_id' => $user->id,
                'product_id' => $productId,
                'status' => 'completed',
                'amount' => $product->price,
                'description' => 'پرداخت از کیف پول',
            ]));

            // Debit balance.
            $this->users->save(new User($user->toArray() + ['balance' => $user->balance - $product->price]));

            // Record the payment.
            $this->payments->savePayment(new Payment([
                'user_id' => $user->id,
                'invoice_id' => $invoice->id,
                'method' => 'wallet',
                'status' => 'completed',
                'amount' => $product->price,
                'order_id' => $invoice->uuid,
                'paid_at' => now(),
            ]));

            // Activate subscription.
            $freshUser = $this->users->findByTelegramId($telegramId);
            if ($freshUser === null) {
                throw new RuntimeException('یه خطا رخ داد؛ دوباره تلاش کنید.');
            }

            $this->purchase->activate($freshUser, $product);

            $this->logger->info('Wallet payment settled', ['invoice' => $invoice->uuid, 'user' => $user->id]);

            return $invoice;
        });
    }

    /**
     * Handle a gateway callback (e.g. Zarinpal return).
     *
     * @param array<string, mixed> $params
     * @return array{status: string, invoice: ?Invoice, reference: string}
     */
    public function handleCallback(string $lookup, array $params): array
    {
        $payment = $this->payments->findByOrderId((string) ($params['order_id'] ?? $lookup));
        if ($payment === null) {
            // Some gateways only give us the external ref.
            $payment = $this->findByExternalRef($lookup);
        }

        if ($payment === null) {
            return ['status' => 'failed', 'invoice' => null, 'reference' => ''];
        }

        $gateway = $this->gateways[$payment->method] ?? null;
        if ($gateway === null) {
            return ['status' => 'failed', 'invoice' => null, 'reference' => ''];
        }

        $verification = $gateway->verifyPayment($lookup, $params);

        if ($verification['status'] === 'completed') {
            $this->confirm($payment->orderId, $verification['reference']);
            $invoice = $this->payments->findInvoiceById((int) $payment->invoiceId);

            return ['status' => 'completed', 'invoice' => $invoice, 'reference' => $verification['reference']];
        }

        // Mark failed when the gateway explicitly says so.
        if ($verification['status'] === 'failed') {
            $this->fail($payment->orderId, $verification['message'] ?? '');
        }

        return [
            'status' => $verification['status'],
            'invoice' => $this->payments->findInvoiceById((int) $payment->invoiceId),
            'reference' => $verification['reference'],
        ];
    }

    /**
     * Confirm a pending payment: mark completed, update invoice, activate the
     * subscription (or renew it if one exists). Idempotent.
     *
     * @throws RuntimeException
     */
    public function confirm(string $orderId, string $reference = ''): bool
    {
        $payment = $this->payments->findByOrderId($orderId);
        if ($payment === null) {
            throw new RuntimeException('پرداختی با این شناسه یافت نشد.');
        }

        if ($payment->isCompleted()) {
            return true; // already settled — idempotent.
        }

        return $this->db->transaction(function () use ($payment, $reference): bool {
            // 1. Mark the payment completed.
            $this->payments->savePayment(new Payment($payment->toArray() + [
                'status' => 'completed',
                'external_ref' => $reference !== '' ? $reference : $payment->externalRef,
                'paid_at' => now(),
            ]));

            // 2. Update the invoice.
            $invoice = $this->payments->findInvoiceById((int) $payment->invoiceId);
            if ($invoice !== null && !$invoice->isPaid()) {
                $this->payments->saveInvoice(new Invoice($invoice->toArray() + ['status' => 'completed']));
            }

            // 3. Activate or renew the subscription.
            $user = $this->users->findById((int) $payment->userId);
            $product = $this->subscriptions->findProductById((int) ($invoice?->productId ?? 0));

            if ($user !== null && $product !== null) {
                $active = $this->subscriptions->findActiveByUserAndServer(
                    (int) $user->id,
                    (int) $product->serverId
                );

                if ($active !== null && $active->isActive()) {
                    // Current active subscription → extend it.
                    $this->renewal->renew($user, $active, $product);
                } else {
                    // No active sub on this server → provision a new one.
                    $this->purchase->activate($user, $product);
                }
            }

            $this->logger->info('Payment confirmed', ['order_id' => $payment->orderId, 'reference' => $reference]);

            return true;
        });
    }

    /**
     * Mark a pending payment as failed.
     */
    public function fail(string $orderId, string $reason = ''): bool
    {
        $payment = $this->payments->findByOrderId($orderId);
        if ($payment === null || $payment->isCompleted()) {
            return false;
        }

        $this->payments->savePayment(new Payment($payment->toArray() + [
            'status' => 'failed',
            'meta' => json_encode(array_merge($payment->metaBag(), ['failure_reason' => $reason])),
        ]));

        $invoice = $this->payments->findInvoiceById((int) $payment->invoiceId);
        if ($invoice !== null) {
            $this->payments->saveInvoice(new Invoice($invoice->toArray() + ['status' => 'failed']));
        }

        $this->logger->warning('Payment failed', ['order_id' => $orderId, 'reason' => $reason]);

        return true;
    }

    /**
     * Find a payment by a gateway external reference.
     */
    private function findByExternalRef(string $ref): ?Payment
    {
        // Fallback scan — small tables, acceptable for admin/gateway matching.
        foreach ($this->payments->paymentsPending() as $payment) {
            if ($payment->externalRef === $ref) {
                return $payment;
            }
        }

        return null;
    }
}