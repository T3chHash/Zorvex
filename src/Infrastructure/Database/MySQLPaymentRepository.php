<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Database;

use Zorvex\Core\Database;
use Zorvex\Domain\Entity\Invoice;
use Zorvex\Domain\Entity\Payment;
use Zorvex\Domain\Repository\PaymentRepository;

/**
 * MySQL-backed payment and invoice repository.
 *
 * @package Zorvex\Infrastructure\Database
 */
final class MySQLPaymentRepository implements PaymentRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findPaymentById(int $id): ?Payment
    {
        $row = $this->db->fetch('SELECT * FROM payments WHERE id = ? LIMIT 1', [$id]);

        return $row === null ? null : new Payment($row);
    }

    public function findByOrderId(string $orderId): ?Payment
    {
        $row = $this->db->fetch('SELECT * FROM payments WHERE order_id = ? LIMIT 1', [$orderId]);

        return $row === null ? null : new Payment($row);
    }

    public function paymentsByUser(int $userId, int $limit = 20): array
    {
        $rows = $this->db->all(
            'SELECT * FROM payments WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . max(1, $limit),
            [$userId]
        );

        return array_map(static fn (array $row): Payment => new Payment($row), $rows);
    }

    public function paymentsPending(): array
    {
        $rows = $this->db->all(
            'SELECT * FROM payments WHERE status = ? ORDER BY created_at ASC',
            ['pending']
        );

        return array_map(static fn (array $row): Payment => new Payment($row), $rows);
    }

    public function savePayment(Payment $payment): Payment
    {
        $data = $payment->toArray();

        if ($payment->id !== null) {
            $this->db->execute(
                'UPDATE payments SET user_id = ?, invoice_id = ?, method = ?, status = ?, amount = ?, ' .
                'currency = ?, order_id = ?, external_ref = ?, proof_file = ?, meta = ?, paid_at = ? WHERE id = ?',
                [
                    $payment->userId,
                    $payment->invoiceId,
                    $payment->method,
                    $payment->status,
                    $payment->amount,
                    $payment->currency,
                    $payment->orderId,
                    $payment->externalRef,
                    $payment->proofFile,
                    $payment->meta,
                    $payment->paidAt,
                    $payment->id,
                ]
            );

            return new Payment($data);
        }

        $this->db->execute(
            'INSERT INTO payments (user_id, invoice_id, method, status, amount, currency, order_id, external_ref, proof_file, meta, created_at, paid_at) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $payment->userId,
                $payment->invoiceId,
                $payment->method,
                $payment->status,
                $payment->amount,
                $payment->currency,
                $payment->orderId,
                $payment->externalRef,
                $payment->proofFile,
                $payment->meta,
                now(),
                $payment->paidAt,
            ]
        );

        $data['id'] = (int) $this->db->lastInsertId();

        return new Payment($data);
    }

    public function countPayments(): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM payments');
    }

    public function totalRevenue(): float
    {
        return (float) $this->db->scalar(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = ?',
            ['completed']
        );
    }

    public function revenueBetween(string $from, string $to): float
    {
        return (float) $this->db->scalar(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = ? AND created_at BETWEEN ? AND ?',
            ['completed', $from, $to]
        );
    }

    // ---- Invoices ----

    public function findInvoiceById(int $id): ?Invoice
    {
        $row = $this->db->fetch('SELECT * FROM invoices WHERE id = ? LIMIT 1', [$id]);

        return $row === null ? null : new Invoice($row);
    }

    public function findByUuid(string $uuid): ?Invoice
    {
        $row = $this->db->fetch('SELECT * FROM invoices WHERE uuid = ? LIMIT 1', [$uuid]);

        return $row === null ? null : new Invoice($row);
    }

    public function findPendingInvoiceByUserAndProduct(int $userId, int $productId): ?Invoice
    {
        $row = $this->db->fetch(
            'SELECT * FROM invoices WHERE user_id = ? AND product_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$userId, $productId, 'pending']
        );

        return $row === null ? null : new Invoice($row);
    }

    public function saveInvoice(Invoice $invoice): Invoice
    {
        $data = $invoice->toArray();

        if ($invoice->id !== null) {
            $this->db->execute(
                'UPDATE invoices SET uuid = ?, user_id = ?, subscription_id = ?, product_id = ?, status = ?, amount = ?, description = ? WHERE id = ?',
                [
                    $invoice->uuid,
                    $invoice->userId,
                    $invoice->subscriptionId,
                    $invoice->productId,
                    $invoice->status,
                    $invoice->amount,
                    $invoice->description,
                    $invoice->id,
                ]
            );

            return new Invoice($data);
        }

        $this->db->execute(
            'INSERT INTO invoices (uuid, user_id, subscription_id, product_id, status, amount, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $invoice->uuid,
                $invoice->userId,
                $invoice->subscriptionId,
                $invoice->productId,
                $invoice->status,
                $invoice->amount,
                $invoice->description,
                now(),
            ]
        );

        $data['id'] = (int) $this->db->lastInsertId();

        return new Invoice($data);
    }

    public function deleteInvoice(int $id): bool
    {
        return $this->db->execute('DELETE FROM invoices WHERE id = ?', [$id]);
    }
}