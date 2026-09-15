<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram\Keyboards;

/**
 * Service (product / subscription) keyboard builder.
 *
 * @package Zorvex\Infrastructure\Telegram\Keyboards
 */
final class ServiceKeyboard
{
    /**
     * Build the "available products" inline keyboard.
     *
     * @param list<array<string, mixed>> $products
     */
    public function services(array $products = []): array
    {
        $rows = [];
        foreach ($products as $product) {
            $label = sprintf(
                '%s — %s | %s روز | %s',
                (string) ($product['name'] ?? ''),
                format_price((float) ($product['price'] ?? 0), 'تومان'),
                (string) ($product['duration_days'] ?? ''),
                (string) ($product['traffic_label'] ?? '')
            );

            $rows[] = [[
                'text' => '🛒 ' . $label,
                'callback_data' => 'buy:' . (string) ($product['id'] ?? ''),
            ]];
        }

        $rows[] = [[
            'text' => '🔙 بازگشت به منو',
            'callback_data' => 'nav:home',
        ]];

        return ['inline_keyboard' => $rows];
    }

    /**
     * Keyboard shown for a specific product: choose payment method.
     *
     * @param list<array<string, string>> $methods e.g. [['id' => 'crypto', 'label' => 'کریپتو']]
     */
    public function payMethods(int $productId, array $methods = []): array
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
     * Subscription actions (extension, config, suspend, renew).
     *
     * @param array<string, mixed> $subscription
     */
    public function subscriptionActions(array $subscription = []): array
    {
        $id = (string) ($subscription['id'] ?? '');
        $rows = [];

        $rows[] = [
            ['text' => '📜 دریافت کانفیگ', 'callback_data' => 'sub:config:' . $id],
            ['text' => '🔄 تمدید', 'callback_data' => 'sub:renew:' . $id],
        ];

        if (($subscription['status'] ?? '') === 'active') {
            $rows[] = [[
                'text' => '🔍 وضعیت استفاده',
                'callback_data' => 'sub:usage:' . $id,
            ]];
        }

        $rows[] = [[
            'text' => '🔙 بازگشت به منو',
            'callback_data' => 'nav:home',
        ]];

        return ['inline_keyboard' => $rows];
    }

    /**
     * Confirmation buttons for a purchase.
     */
    public function confirmPurchase(int $productId, string $method): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '✅ تأیید و پرداخت', 'callback_data' => 'confirm:' . $productId . ':' . $method],
                    ['text' => '❌ انصراف', 'callback_data' => 'nav:services'],
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