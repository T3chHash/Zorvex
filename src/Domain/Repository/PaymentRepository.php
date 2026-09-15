<?php

declare(strict_types=1);

namespace Zorvex\Domain\Repository;

use Zorvex\Domain\Entity\Invoice;
use Zorvex\Domain\Entity\Payment;

/**
 * Payment and invoice repository interface.
 *
 * @package Zorvex\Domain\Repository
 */
interface PaymentRepository
{
    public function findPaymentById(int $id): ?Payment;

    public function findByOrderId(string $orderId): ?Payment;

    /**
     * @return list<Payment>
     */
    public function paymentsByUser(int $userId, int $limit = 20): array;

    /**
     * @return list<Payment>
     */
    public function paymentsPending(): array;

    public function savePayment(Payment $payment): Payment;

    public function countPayments(): int;

    public function totalRevenue(): float;

    public function revenueBetween(string $from, string $to): float;

    // ---- Invoices ----

    public function findInvoiceById(int $id): ?Invoice;

    public function findByUuid(string $uuid): ?Invoice;

    public function findPendingInvoiceByUserAndProduct(int $userId, int $productId): ?Invoice;

    public function saveInvoice(Invoice $invoice): Invoice;

    public function deleteInvoice(int $id): bool;
}