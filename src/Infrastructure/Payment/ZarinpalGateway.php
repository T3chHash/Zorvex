<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Payment;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Zorvex\Domain\Entity\Invoice;
use Zorvex\Domain\Service\PaymentGateway;

/**
 * Zarinpal (Iranian) online payment gateway.
 *
 * Implements the Zarinpal REST API v4:
 *   sandbox:  https://sandbox.zarinpal.com/pg/v4/payment/request.json
 *   payment:  https://sandbox.zarinpal.com/pg/StartPay/{authority}
 *   verify:   https://sandbox.zarinpal.com/pg/v4/payment/verify.json
 *
 * @package Zorvex\Infrastructure\Payment
 */
final class ZarinpalGateway implements PaymentGateway
{
    private const GATEWAY_URL = 'https://www.zarinpal.com/pg/StartPay/';
    private const SANDBOX_URL = 'https://sandbox.zarinpal.com/pg/StartPay/';

    private const REQUEST_URL = 'https://payment.zarinpal.com/v4/payment/request.json';
    private const SANDBOX_REQUEST_URL = 'https://sandbox.zarinpal.com/pg/v4/payment/request.json';
    private const VERIFY_URL = 'https://payment.zarinpal.com/v4/payment/verify.json';
    private const SANDBOX_VERIFY_URL = 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function id(): string
    {
        return 'zarinpal';
    }

    public function supports(Invoice $invoice): bool
    {
        return (bool) (config('payment.zarinpal.enabled') ?? true);
    }

    public function createPayment(Invoice $invoice): array
    {
        $merchant = (string) (config('payment.zarinpal.merchant_id') ?? '');
        if ($merchant === '') {
            throw new RuntimeException('Zarinpal merchant id is not configured.');
        }

        $amountIrr = (int) round($invoice->amount * 10); // Toman → Rial
        $callback = (string) (config('payment.zarinpal.callback') ?? '');

        $response = $this->request(
            $this->isSandbox() ? self::SANDBOX_REQUEST_URL : self::REQUEST_URL,
            [
                'merchant_id' => $merchant,
                'amount' => $amountIrr,
                'description' => $invoice->description ?? 'Zorvex VPN purchase',
                'callback_url' => $callback,
                'metadata' => [
                    'invoice_uuid' => $invoice->uuid,
                    'mobile' => '',
                ],
            ]
        );

        $data = $response['data'] ?? [];
        $code = $data['code'] ?? null;
        $authority = $data['authority'] ?? null;

        if ($code !== 100 || !is_string($authority) || $authority === '') {
            $this->logger->error('Zarinpal request failed', ['response' => $response, 'invoice' => $invoice->uuid]);
            throw new RuntimeException('Zarinpal could not initiate the payment.');
        }

        return [
            'pay_url' => ($this->isSandbox() ? self::SANDBOX_URL : self::GATEWAY_URL) . $authority,
            'lookup' => $authority,
            'extra' => ['authority' => $authority],
        ];
    }

    public function verifyPayment(string $lookup, array $params = []): array
    {
        $merchant = (string) (config('payment.zarinpal.merchant_id') ?? '');
        $amountIrr = (int) ($params['amount'] ?? 0);

        $response = $this->request(
            $this->isSandbox() ? self::SANDBOX_VERIFY_URL : self::VERIFY_URL,
            [
                'merchant_id' => $merchant,
                'amount' => $amountIrr,
                'authority' => $lookup,
            ]
        );

        $data = $response['data'] ?? [];
        $code = $data['code'] ?? null;
        $refId = (string) ($data['ref_id'] ?? '');

        if ($code === 100 && $refId !== '') {
            return ['status' => 'completed', 'reference' => $refId];
        }

        return [
            'status' => 'failed',
            'reference' => '',
            'message' => 'Payment verification failed (code ' . (string) $code . ').',
        ];
    }

    public function label(): string
    {
        return 'زرین‌پال';
    }

    public function color(): string
    {
        return '#ffd300';
    }

    private function isSandbox(): bool
    {
        return (bool) (config('payment.zarinpal.sandbox') ?? false);
    }

    /**
     * Perform a JSON POST against the Zarinpal API.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $decoded = $body !== false ? json_decode((string) $body, true) : null;

        if ($status >= 400 || $decoded === null) {
            $this->logger->error('Zarinpal HTTP error', ['url' => $url, 'status' => $status, 'body' => $body, 'error' => $error]);
            throw new RuntimeException('Zarinpal is unreachable (HTTP ' . $status . ').');
        }

        return is_array($decoded) ? $decoded : [];
    }
}