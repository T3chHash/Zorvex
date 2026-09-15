<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram\Keyboards;

/**
 * Payment-state keyboard builder.
 *
 * @package Zorvex\Infrastructure\Telegram\Keyboards
 */
final class PaymentKeyboard
{
    /**
     * Buttons shown after a payment was initiated.
     *
     * @param array<string, mixed> $payment e.g. ['order_id' => ...]
     */
    public function paymentActions(array $payment = []): array
    {
        $orderId = (string) ($payment['order_id'] ?? '');
        $rows = [];

        $rows[] = [
            ['text' => '📷 ارسال رسید', 'callback_data' => 'pay:receipt:' . $orderId],
            ['text' => '🔍 بررسی وضعیت', 'callback_data' => 'pay:status:' . $orderId],
        ];
        $rows[] = [
            ['text' => '❌ لغو پرداخت', 'callback_data' => 'pay:cancel:' . $orderId],
            ['text' => '🔙 منو', 'callback_data' => 'nav:home'],
        ];

        return ['inline_keyboard' => $rows];
    }

    /**
     * Keyboard for picking a payment method for an active invoice.
     *
     * @param list<array<string, string>> $methods
     */
    public function gatewayChoices(int $productId, array $methods = []): array
    {
        $rows = [];
        foreach ($methods as $method) {
            $rows[] = [[
                'text' => '💳 ' . ($method['label'] ?? $method['id']),
                'callback_data' => 'pay:' . $productId . ':' . ($method['id'] ?? ''),
            ]];
        }
        $rows[] = [[
            'text' => '🔙 بازگشت',
            'callback_data' => 'nav:services',
        ]];

        return ['inline_keyboard' => $rows];
    }

    /**
     * Admin approval controls for a manual/crypto pending payment.
     */
    public function adminReview(int $orderId): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تأیید پرداخت', 'callback_data' => 'admin:pay:approve:' . $orderId],
                    ['text' => '❌ رد پرداخت', 'callback_data' => 'admin:pay:reject:' . $orderId],
                ],
            ],
        ];
    }

    public function backHome(): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '🔙 بازگشت به منو', 'callback_data' => 'nav:home'],
            ]],
        ];
    }
}